<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\Alert;
use App\Models\RevealLog;
use App\Services\ProofImageProcessor;
use App\Services\RevealRateLimiter;
use App\Services\TotpService;
use Illuminate\Support\Facades\Storage;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use App\Enums\AlertType;

/**
 * The activation screen — the security-sensitive heart of the app.
 * Every account bundles both a PSN and an EA login, so the employee sees
 * both sets of credentials directly (the employee needs them to actually
 * work, not just glance at them), can generate fresh TOTP codes for both
 * platforms on demand, and then marks the activation as done or flags
 * exactly which piece of data was wrong. Every view is written to
 * reveal_logs for audit purposes.
 */
class Activation extends Page
{
    protected static string $view = 'filament.app.pages.activation';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * The six things an employee can flag as wrong when activation fails.
     *
     * @var array<string,string>
     */
    public const WRONG_DATA_OPTIONS = [
        'psn_email'    => 'بريد PSN',
        'psn_password' => 'رمز PSN',
        'psn_totp'     => 'كود PSN',
        'ea_email'     => 'بريد EA',
        'ea_password'  => 'رمز EA',
        'ea_totp'      => 'كود EA',
    ];

    public static function getRoutePath(?\Filament\Panel $panel = null): string
    {
        return '/activation/{assignment}';
    }

    public AccountAssignment $assignment;

    public ?string $revealedPsnEmail = null;
    public ?string $revealedPsnPassword = null;
    public ?string $revealedEaEmail = null;
    public ?string $revealedEaPassword = null;
    public ?string $revealedEaBackupCode1 = null;
    public ?string $revealedEaBackupCode2 = null;
    public bool $credentialsBlockedByWorkHours = false;

    /** Set when this isn't the account the employee is locked into. */
    public ?AccountAssignment $blockingLock = null;

    public ?string $totpCodePsn = null;
    public ?string $totpCodeEa = null;
    public int $totpSecondsLeft = 0;

    /**
     * Unix timestamp of the last successful TOTP generation. The visible
     * code lives for `config('fc27ac.totp_display_seconds')` from this
     * moment, independent of the 30-second TOTP window — PSN/EA verifiers
     * accept a ±1 window skew (~60s total span), so the code shown stays
     * valid on the console the whole time. Null when no code is visible.
     */
    public ?int $totpGeneratedAt = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isEmployee() ?? false;
    }

    /**
     * Push-based updates:
     * - totp.approved: supervisor granted an extra code — refresh the
     *   assignment so the button flips and show the "تمت الموافقة"
     *   notification.
     * - tenant.freeze: owner flipped the kill switch — full page refresh
     *   so credentials disappear (freeze) or reappear (unfreeze).
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $userId   = (int) auth()->id();
        $tenantId = (int) auth()->user()?->tenant_id;

        return [
            'echo-private:user.'.$userId.',.totp.approved'             => 'onTotpApproved',
            'echo-private:user.'.$userId.',.backup_codes.approved'     => 'onBackupCodesApproved',
            'echo-private:tenant.'.$tenantId.',.tenant.freeze'         => '$refresh',
        ];
    }

    public function onBackupCodesApproved(array $payload = []): void
    {
        if (($payload['assignmentId'] ?? null) !== $this->assignment->id) {
            return;
        }

        $this->assignment->refresh();
        $account = $this->assignment->account;
        $this->revealedEaBackupCode1 = $account->ea_backup_code_1;
        $this->revealedEaBackupCode2 = $account->ea_backup_code_2;

        Notification::make()
            ->success()
            ->title('تمت الموافقة على عرض Backup Codes')
            ->body('الأكواد ظاهرة الآن في صفحة التفعيل.')
            ->send();
    }

    public function onTotpApproved(array $payload = []): void
    {
        // Only act if the event is for THIS assignment — a user with two
        // tabs open on two different activations would otherwise see the
        // other tab's approval land on this page too.
        if (($payload['assignmentId'] ?? null) !== $this->assignment->id) {
            return;
        }

        $this->assignment->refresh();

        $platform = strtoupper((string) ($payload['platform'] ?? ''));
        Notification::make()
            ->success()
            ->title('تمت الموافقة على كود '.$platform.' إضافي')
            ->body('اضغط زر التوليد الآن.')
            ->send();
    }

    public function mount(AccountAssignment $assignment): void
    {
        // Also match the tenant — route model binding loads by primary key
        // without a tenant filter, so without this check a user could open
        // /app/t/{their-tenant}/activation/{other-tenant-id} whenever the
        // foreign row's employee_id happens to collide with theirs.
        $user = auth()->user();
        abort_unless(
            $user !== null
                && $assignment->employee_id === $user->id
                && $assignment->tenant_id === $user->tenant_id,
            404,
        );

        $this->assignment = $assignment->loadMissing('account');

        // The account row is gone (owner force-deleted it after this row
        // was assigned). Sending the employee to a page whose entire body
        // reads $assignment->account->... would 500 — bounce back with a
        // clear notice instead. The assignment row itself survives so the
        // employee's completion history stays intact.
        if ($this->assignment->account === null) {
            Notification::make()
                ->warning()
                ->title('هذا الحساب لم يعد موجوداً')
                ->body('تم حذفه من قِبل المالك — تواصل مع مشرفك إذا كنت وسط تفعيل.')
                ->send();

            $this->redirect(MyAccounts::getUrl(), navigate: true);
            return;
        }

        // Once the employee has submitted proof (awaiting_review) or the
        // supervisor accepted it (completed), there is nothing more for
        // them to do here. Keeping them on this page just leaves the
        // account's credentials on screen after the job is done — bounce
        // them back to "حساباتي" with a short notice. If the supervisor
        // later rejects the proof, the assignment moves to in_progress
        // (with rejection_reason set) and the redirect stops applying.
        if (in_array($this->assignment->status, [
            AccountAssignment::STATUS_AWAITING_REVIEW,
            AccountAssignment::STATUS_COMPLETED,
        ], true)) {
            $title = $this->assignment->status === AccountAssignment::STATUS_AWAITING_REVIEW
                ? 'الحساب بانتظار مراجعة المشرف'
                : 'الحساب مكتمل';

            Notification::make()
                ->info()
                ->title($title)
                ->body('لا تحتاج تفتح صفحة التفعيل مرة أخرى.')
                ->send();

            $this->redirect(MyAccounts::getUrl(), navigate: true);
            return;
        }

        // Employees may keep up to MAX_CONCURRENT_ACTIVATIONS started
        // accounts open at once. When they've hit that cap and open a
        // NEW activation, block it here rather than silently switching
        // to one of the started ones — which would read as "it just
        // changed accounts on me".
        $locked = AccountAssignment::lockedFor((int) auth()->id(), $this->assignment->id);
        if ($locked !== null) {
            $this->blockingLock = $locked;
            return;
        }

        if ($this->assignment->status === AccountAssignment::STATUS_PENDING) {
            $this->assignment->update([
                'status'     => AccountAssignment::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);
        }

        // Emergency freeze — owner-triggered kill switch. Refuses to
        // reveal ANY credentials or generate ANY codes tenant-wide. The
        // panel-level banner (see AppPanelProvider) explains WHY to the
        // user so this doesn't look like a bug.
        if ($this->assignment->tenant->isFrozen()) {
            $this->credentialsBlockedByWorkHours = true;
            return;
        }

        if ($this->guardRateLimit('reveal_credentials')) {
            $account = $this->assignment->account;

            $this->revealedPsnEmail    = $account->email;
            $this->revealedPsnPassword = $account->psn_password;
            $this->revealedEaEmail     = $account->effectiveEaEmail();
            $this->revealedEaPassword  = $account->effectiveEaPassword();

            // Backup codes are a manager-gated fallback for EA TOTP failures
            // — never revealed alongside the credentials. Only surfaced
            // after a supervisor/manager approves an explicit request
            // (see requestBackupCodesAction below).
            if ($this->assignment->ea_backup_codes_approved_at !== null) {
                $this->revealedEaBackupCode1 = $account->ea_backup_code_1;
                $this->revealedEaBackupCode2 = $account->ea_backup_code_2;
            }

            $this->logAction('reveal_credentials', $account);

            // Timing marker for step measurements — set once, on the
            // employee's first actual view. Subsequent visits don't reset
            // it because the goal is "how long from first sight to
            // submission", not "how long since last visit".
            if ($this->assignment->credentials_revealed_at === null) {
                $this->assignment->update(['credentials_revealed_at' => now()]);
            }
        }
    }

    /**
     * Shared guard for the TOTP-generate actions — freeze + working hours.
     * Working hours already has its own notification; the freeze case needs
     * its own explicit one so the employee understands why nothing happens
     * on tap. Returns false when the action must abort.
     */
    protected function guardSensitiveAction(): bool
    {
        if ($this->assignment->tenant->isFrozen()) {
            Notification::make()
                ->danger()
                ->title('النظام في وضع الطوارئ')
                ->body('توقفت كل العمليات الحساسة مؤقتاً. تواصل مع المالك.')
                ->send();
            return false;
        }

        return true;
    }

    /**
     * The employee cannot act on an assignment that's mid-review (waiting
     * on the manager) or already fully approved.
     */
    protected function isLocked(): bool
    {
        return in_array($this->assignment->status, [
            AccountAssignment::STATUS_AWAITING_REVIEW,
            AccountAssignment::STATUS_COMPLETED,
        ], true);
    }

    public function getTitle(): string|Htmlable
    {
        return 'تفعيل حساب #'.$this->assignment->account_id;
    }

    // ── Actions ──────────────────────────────────────────────────────

    /**
     * PSN and EA are gated separately: the real activation flow needs one
     * PSN confirmation and two EA confirmations, no more. Past that, the
     * employee can't just keep pulling fresh codes — a supervisor has to
     * approve additional ones via an alert.
     */
    public function generatePsnTotpAction(): Action
    {
        return Action::make('generatePsnTotp')
            ->label(function (): string {
                if ($this->assignment->canGeneratePsnTotp()) {
                    return 'توليد كود PSN ('.$this->assignment->psn_totp_generations.'/'.$this->assignment->psnTotpAllowance().')';
                }
                return $this->hasPendingTotpApproval('psn') ? 'بانتظار موافقة المشرف' : 'إرسال طلب موافقة للمشرف';
            })
            ->icon(fn (): string => $this->assignment->canGeneratePsnTotp() ? 'heroicon-o-shield-check' : 'heroicon-o-paper-airplane')
            ->color(fn (): string => $this->assignment->canGeneratePsnTotp() ? 'primary' : 'warning')
            ->disabled(fn (): bool => $this->isLocked() || $this->hasPendingTotpApproval('psn'))
            ->action(function (TotpService $totp): void {
                if (! $this->guardSensitiveAction()
                    || ! $this->guardRateLimit('generate_totp_psn')) {
                    return;
                }

                // Check-then-increment must be atomic — without the row
                // lock, two simultaneous clicks (or two open tabs) can
                // both pass canGeneratePsnTotp() before either increments,
                // double-spending the 1-code allowance.
                $granted = DB::transaction(function (): bool {
                    $locked = AccountAssignment::query()->lockForUpdate()->find($this->assignment->id);
                    if ($locked === null || ! $locked->canGeneratePsnTotp()) {
                        return false;
                    }
                    $locked->increment('psn_totp_generations');
                    return true;
                });
                $this->assignment->refresh();

                if (! $granted) {
                    $this->requestTotpApproval('psn');
                    return;
                }

                $account = $this->assignment->account;
                $result = $totp->currentCode($account->psn_totp_seed);

                $this->totpCodePsn = $result['code'];
                $this->totpGeneratedAt = now()->timestamp;
                $this->totpSecondsLeft = (int) config('fc27ac.totp_display_seconds');

                $this->logAction('generate_totp_psn', $account);

                if ($this->assignment->first_totp_at === null) {
                    $this->assignment->update(['first_totp_at' => now()]);
                }
            });
    }

    public function generateEaTotpAction(): Action
    {
        return Action::make('generateEaTotp')
            ->label(function (): string {
                if ($this->assignment->canGenerateEaTotp()) {
                    return 'توليد كود EA ('.$this->assignment->ea_totp_generations.'/'.$this->assignment->eaTotpAllowance().')';
                }
                return $this->hasPendingTotpApproval('ea') ? 'بانتظار موافقة المشرف' : 'إرسال طلب موافقة للمشرف';
            })
            ->icon(fn (): string => $this->assignment->canGenerateEaTotp() ? 'heroicon-o-shield-check' : 'heroicon-o-paper-airplane')
            ->color(fn (): string => $this->assignment->canGenerateEaTotp() ? 'primary' : 'warning')
            ->disabled(fn (): bool => $this->isLocked() || $this->hasPendingTotpApproval('ea'))
            ->action(function (TotpService $totp): void {
                if (! $this->guardSensitiveAction()
                    || ! $this->guardRateLimit('generate_totp_ea')) {
                    return;
                }

                $granted = DB::transaction(function (): bool {
                    $locked = AccountAssignment::query()->lockForUpdate()->find($this->assignment->id);
                    if ($locked === null || ! $locked->canGenerateEaTotp()) {
                        return false;
                    }
                    $locked->increment('ea_totp_generations');
                    return true;
                });
                $this->assignment->refresh();

                if (! $granted) {
                    $this->requestTotpApproval('ea');
                    return;
                }

                $account = $this->assignment->account;
                $result = $totp->currentCode($account->ea_totp_seed);

                $this->totpCodeEa = $result['code'];
                $this->totpGeneratedAt = now()->timestamp;
                $this->totpSecondsLeft = (int) config('fc27ac.totp_display_seconds');

                $this->logAction('generate_totp_ea', $account);

                if ($this->assignment->first_totp_at === null) {
                    $this->assignment->update(['first_totp_at' => now()]);
                }
            });
    }

    /**
     * 1s tick wired by wire:poll while any code is on screen. Recomputes
     * the seconds remaining until the code auto-hides — no client-side
     * timer that can drift or be tampered with. Bounded: only a handful
     * of hits per generated code (totp_display_seconds), no polling at
     * rest.
     */
    public function tickTotp(TotpService $totp): void
    {
        if ($this->totpCodePsn === null && $this->totpCodeEa === null) {
            return;
        }

        $this->refreshTotpDisplay($totp);
    }

    /**
     * "طلب Backup Codes" — a manager-gated fallback for when EA TOTP
     * misbehaves. The employee cannot see the codes on their own; a
     * supervisor or the manager over their office has to evaluate the
     * request and approve it. Same alert-and-approval shape as the
     * TOTP-limit request above so operators only learn one workflow.
     */
    public function requestBackupCodesAction(): Action
    {
        return Action::make('requestBackupCodes')
            ->label(function (): string {
                if ($this->assignment->ea_backup_codes_approved_at !== null) {
                    return 'Backup Codes متاحة';
                }
                return $this->hasPendingBackupCodesRequest()
                    ? 'بانتظار موافقة المشرف على Backup Codes'
                    : 'طلب Backup Codes';
            })
            ->icon(fn (): string => $this->assignment->ea_backup_codes_approved_at !== null
                ? 'heroicon-o-shield-check'
                : 'heroicon-o-paper-airplane')
            ->color(fn (): string => $this->assignment->ea_backup_codes_approved_at !== null
                ? 'success'
                : 'warning')
            ->visible(fn (): bool => $this->assignment->account->hasEaBackupCodes())
            ->disabled(fn (): bool => $this->isLocked()
                || $this->assignment->ea_backup_codes_approved_at !== null
                || $this->hasPendingBackupCodesRequest())
            ->requiresConfirmation()
            ->modalHeading('طلب Backup Codes')
            ->modalDescription('يُرسَل طلب للمشرف/المدير لتقييم ما إذا كنت تحتاج أكواد الاحتياط لتجاوز مشكلة TOTP. لن تظهر الأكواد إلا بعد الموافقة.')
            ->action(function (): void {
                if (! $this->guardSensitiveAction()) {
                    return;
                }

                $account = $this->assignment->account;

                $this->logAction('request_backup_codes', $account);

                Alert::raise([
                    'tenant_id'  => $account->tenant_id,
                    'user_id'    => auth()->id(),
                    'account_id' => $account->id,
                    'type'       => AlertType::BackupCodesReveal,
                    'severity'   => 'medium',
                    'message'    => 'الموظف يطلب عرض Backup Codes بسبب مشكلة في TOTP',
                    'payload'    => ['assignment_id' => $this->assignment->id],
                ], dedupKey: "backup_codes_reveal:{$this->assignment->id}");

                Notification::make()
                    ->warning()
                    ->title('أُرسل طلب Backup Codes للمشرف')
                    ->body('ستظهر الأكواد بعد الموافقة.')
                    ->send();
            });
    }

    /**
     * Lightweight poll target for the pending-approval fallback banner —
     * fetches only the approval timestamp instead of triggering a full
     * component refresh (which would re-run mount, re-render the whole
     * credential display, and re-hit reveal_logs). When the flag flips,
     * hydrate the codes onto the component so the blade's @if picks
     * them up on the next render.
     */
    public function checkBackupCodesApproval(): void
    {
        // Single fetch — assignment + account together. The old shape ran
        // three queries per tick (scalar + refresh + lazy account) and,
        // more importantly, refresh() would throw ModelNotFoundException
        // if the owner's bulk-reassign wiped this pending assignment
        // between ticks, 500ing the employee's page. find() short-
        // circuits on null instead.
        $fresh = AccountAssignment::query()
            ->with('account', 'tenant')
            ->find($this->assignment->id);

        if ($fresh === null || $fresh->ea_backup_codes_approved_at === null) {
            return;
        }

        // Same gate that hides the primary credentials must also hide the
        // codes — otherwise a page that stayed open across a freeze
        // boundary would leak backup codes on the next poll tick.
        if ($fresh->tenant->isFrozen()) {
            return;
        }

        $this->assignment = $fresh;
        $this->revealedEaBackupCode1 = $fresh->account->ea_backup_code_1;
        $this->revealedEaBackupCode2 = $fresh->account->ea_backup_code_2;
    }

    public function hasPendingBackupCodesRequest(): bool
    {
        return Alert::query()
            ->where('type', AlertType::BackupCodesReveal)
            ->where('resolved', false)
            ->where('user_id', auth()->id())
            ->whereJsonContains('payload->assignment_id', $this->assignment->id)
            ->exists();
    }

    public function hasPendingTotpApproval(string $platform): bool
    {
        return Alert::query()
            ->where('type', AlertType::TotpLimit)
            ->where('resolved', false)
            ->where('user_id', auth()->id())
            ->whereJsonContains('payload->assignment_id', $this->assignment->id)
            ->whereJsonContains('payload->platform', $platform)
            ->exists();
    }

    /**
     * Countdown the visible code from `totp_display_seconds` down to 0,
     * anchored to the server timestamp captured at generation. The code
     * itself stays put on screen the whole lifetime — no window rollover
     * clearing, no auto-refresh to the next window. PSN/EA verifiers
     * accept a ±1 window skew (~60s span), so the generated value is
     * accepted on the console for the whole visible lifetime regardless
     * of where in the 30s TOTP window the press landed.
     *
     * When the lifetime elapses the code is cleared (not rolled to a
     * fresh one): one generation = one visible window, so the employee
     * has to press again — and consume another allowance — to see a
     * new code. Silently rolling forever would defeat the rate-limit.
     *
     * @param  TotpService  $totp  kept for BC — not used, but the poll
     *                             target signature is Livewire-visible.
     */
    public function refreshTotpDisplay(TotpService $totp): void
    {
        if ($this->totpCodePsn === null && $this->totpCodeEa === null) {
            return;
        }

        // Missing timestamp = the property was hydrated from an earlier
        // deploy that didn't track it. Treat "now" as the generation
        // moment so the code doesn't disappear on the next tick.
        if ($this->totpGeneratedAt === null) {
            $this->totpGeneratedAt = now()->timestamp;
        }

        $lifetime = (int) config('fc27ac.totp_display_seconds');
        $elapsed  = now()->timestamp - $this->totpGeneratedAt;
        $this->totpSecondsLeft = max(0, $lifetime - $elapsed);

        if ($this->totpSecondsLeft === 0) {
            $this->totpCodePsn = null;
            $this->totpCodeEa = null;
            $this->totpGeneratedAt = null;
        }
    }

    /**
     * The limit was hit and no request is pending yet — log it, raise one
     * alert for the supervisor, and tell the employee to wait.
     *
     * Idempotent per (assignment, platform): the UI already disables the
     * button once an approval is pending, but two rapid clicks can slip
     * past that check. Alert::raise() with a dedup key collapses the
     * duplicate into the existing open row instead of a second alert.
     */
    protected function requestTotpApproval(string $platform): void
    {
        $account = $this->assignment->account;

        $this->logAction('request_totp_approval', $account);

        Alert::raise([
            'tenant_id'  => $account->tenant_id,
            'user_id'    => auth()->id(),
            'account_id' => $account->id,
            'type'       => AlertType::TotpLimit,
            'severity'   => 'medium',
            'message'    => 'الموظف وصل لحد توليد كود '.strtoupper($platform).' ويطلب موافقة لمزيد',
            'payload'    => ['assignment_id' => $this->assignment->id, 'platform' => $platform],
        ], dedupKey: "totp_limit:{$this->assignment->id}:{$platform}");

        Notification::make()
            ->warning()
            ->title('وصلت للحد المسموح لكود '.strtoupper($platform))
            ->body('أُرسل طلب للمشرف للموافقة على توليد كود إضافي.')
            ->send();
    }

    public function completeAction(): Action
    {
        // Proof is now per-employee: the default is required (safer for
        // new / less-trusted staff), and a manager can toggle it off on
        // a trusted employee via UserResource. This replaced the flat
        // "optional for everyone" and the earlier "mandatory for
        // everyone" — both had complaining users. The employee's row
        // is the source of truth checked below on both the form field
        // and the action closure.
        $requiresProof = (bool) $this->assignment->employee->requires_proof;

        return Action::make('complete')
            ->label('إنهاء التفعيل')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('إنهاء التفعيل')
            ->modalDescription($requiresProof
                ? 'رفع صورة الإثبات مطلوب لهذا التفعيل. التقط بكاميرا الموبايل لشاشة التلفزيون.'
                : 'رفع صورة الإثبات اختياري — تقدر ترفعها لو حابب، أو تخلص من غير.')
            ->disabled(fn (): bool => $this->isLocked())
            ->form([
                FileUpload::make('proof_path')
                    ->label($requiresProof ? 'صورة إثبات التفعيل' : 'صورة إثبات التفعيل (اختياري)')
                    ->required($requiresProof)
                    ->helperText(function () use ($requiresProof): string {
                        $account = $this->assignment->account;
                        $prefix = $requiresProof ? 'مطلوب' : 'اختياري';
                        if ($account->requiresMatches()) {
                            return "{$prefix} — التقط بكاميرا الموبايل لشاشة التلفزيون بعد إنهاء الـ {$account->matches_required} مباريات وتُظهر أرباح المباريات (Match Rewards / MVP). صورة المتصفح لا تُحسب إثباتاً.";
                        }
                        return "{$prefix} — ارفع صورة من كاميرا الموبايل لشاشة التلفزيون. صورة المتصفح لا تُحسب إثباتاً.";
                    })
                    ->image()
                    ->disk('local')
                    ->directory('proofs')
                    ->maxSize(4096),
            ])
            ->action(function (array $data, ProofImageProcessor $processor): void {
                $relativePath = ! empty($data['proof_path']) ? (string) $data['proof_path'] : null;

                // Server-side re-assert of the per-employee proof rule —
                // a hand-crafted Livewire payload could bypass the form
                // ->required() marker and submit without a file. Refuse
                // the submission cleanly in that case; the toast tells
                // the employee what to do.
                if ($relativePath === null && (bool) $this->assignment->employee->requires_proof) {
                    Notification::make()
                        ->danger()
                        ->title('صورة الإثبات مطلوبة')
                        ->body('مديرك اشترط رفع صورة إثبات لكل تفعيل من حسابك.')
                        ->send();
                    return;
                }

                // Hash the pristine bytes, then burn the watermark into
                // the stored file. Failure to process (unreadable file,
                // GD blew up on a corrupt image) must NOT block the
                // employee — they've done the work, we still record the
                // proof and let the supervisor decide. proof_hash stays
                // null in that case, so duplicate detection just doesn't
                // fire, rather than blocking a real submission. Skipped
                // entirely when the employee submitted without a proof
                // (allowed for plain-activation accounts).
                $hash = null;
                if ($relativePath !== null) {
                    $absolute = Storage::disk('local')->path($relativePath);
                    try {
                        $hash = $processor->process(
                            $absolute,
                            auth()->user(),
                            $this->assignment,
                        );
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                DB::transaction(function () use ($relativePath, $hash): void {
                    $this->assignment->update([
                        'status'       => AccountAssignment::STATUS_AWAITING_REVIEW,
                        'proof_path'   => $relativePath,
                        'proof_hash'   => $hash,
                        'submitted_at' => now(),
                    ]);
                    $this->logAction('submit_proof', $this->assignment->account);
                });

                if ($hash !== null) {
                    $this->flagIfDuplicate($hash);
                }

                $this->flagIfSuspiciouslyFast();

                Notification::make()
                    ->success()
                    ->title($relativePath !== null
                        ? 'أُرسل الإثبات — بانتظار موافقة المشرف'
                        : 'أُرسل التفعيل — بانتظار موافقة المشرف')
                    ->send();
                $this->redirect(MyAccounts::getUrl());
            });
    }

    /**
     * Time-of-work sanity check: if the employee submitted proof faster
     * than physically possible (see AccountAssignment::isSuspiciouslyFast
     * for the per-matches threshold), fire a critical alert so the
     * reviewer knows to look closely — the classic case is "screenshot
     * an old match rewards screen from another account, submit without
     * actually playing". Not a hard block: reviewer still decides.
     */
    public function flagIfSuspiciouslyFast(): void
    {
        $assignment = $this->assignment->fresh();
        $matches = $assignment->account->matches_required;

        if (! $assignment->isSuspiciouslyFast($matches)) {
            return;
        }

        $seconds = $assignment->activationSeconds();
        Alert::create([
            'tenant_id'  => $assignment->tenant_id,
            'user_id'    => $assignment->employee_id,
            'account_id' => $assignment->account_id,
            'type'       => AlertType::SuspiciousSpeed,
            'severity'   => 'critical',
            'message'    => "تفعيل مكتمل خلال {$seconds} ثانية فقط — أسرع من الحد الأدنى المتوقع لعدد المباريات المطلوبة ({$matches})",
            'payload'    => [
                'assignment_id'    => $assignment->id,
                'seconds'          => $seconds,
                'matches_required' => $matches,
            ],
        ]);
    }

    /**
     * Look for any earlier submission (in the same tenant, any employee)
     * whose original bytes hash to the same value. A hit is a strong
     * signal of proof re-use — the supervisor gets a critical alert to
     * decide before approving. Not a hard block: false positives are
     * possible in theory (identical unrelated photos), so keep the
     * decision human. Public because tests exercise it directly rather
     * than round-tripping through Filament's file-upload action pipeline.
     */
    public function flagIfDuplicate(string $hash): void
    {
        $original = AccountAssignment::query()
            ->where('tenant_id', $this->assignment->tenant_id)
            ->where('proof_hash', $hash)
            ->where('id', '!=', $this->assignment->id)
            ->orderBy('submitted_at')
            ->first();

        if ($original === null) {
            return;
        }

        Alert::create([
            'tenant_id'  => $this->assignment->tenant_id,
            'user_id'    => $this->assignment->employee_id,
            'account_id' => $this->assignment->account_id,
            'type'       => AlertType::DuplicateProof,
            'severity'   => 'critical',
            'message'    => "صورة الإثبات مطابقة تماماً لإثبات سبق رفعه على حساب #{$original->account_id}",
            'payload'    => [
                'assignment_id'          => $this->assignment->id,
                'original_assignment_id' => $original->id,
                'original_account_id'    => $original->account_id,
                'original_employee_id'   => $original->employee_id,
                'original_submitted_at'  => $original->submitted_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * The employee's own note on this account, so they can tell which
     * console/account is at which stage when working several at once.
     * Not gated by isLocked() — it changes nothing but their own note.
     */
    public function employeeNotesAction(): Action
    {
        return Action::make('employeeNotes')
            ->label(fn (): string => $this->assignment->employee_notes ? 'تعديل الملاحظة' : 'أضف ملاحظة')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->size('sm')
            ->modalHeading('ملاحظاتي على هذا الحساب')
            ->modalSubmitActionLabel('حفظ')
            ->fillForm(fn (): array => ['employee_notes' => $this->assignment->employee_notes])
            ->form([
                Textarea::make('employee_notes')
                    ->label('ملاحظة')
                    ->placeholder('مثال: على جهاز رقم 2 — لعبت مباراة واحدة')
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                $note = trim((string) ($data['employee_notes'] ?? ''));
                $this->assignment->update(['employee_notes' => $note !== '' ? $note : null]);

                Notification::make()->success()->title('حُفظت الملاحظة')->send();
            });
    }

    /**
     * "بيانات خطأ" — the employee picks exactly which field(s) of the
     * PSN/EA credentials are wrong instead of writing free text.
     */
    public function wrongDataAction(): Action
    {
        return Action::make('wrongData')
            ->label('بيانات خطأ')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->form([
                CheckboxList::make('wrong_fields')
                    ->label('حدد البيان الخاطئ')
                    ->options(self::WRONG_DATA_OPTIONS)
                    ->required()
                    ->columns(2),
            ])
            ->disabled(fn (): bool => $this->isLocked())
            ->action(function (array $data): void {
                $labels = collect($data['wrong_fields'])
                    ->map(fn (string $key) => self::WRONG_DATA_OPTIONS[$key] ?? $key)
                    ->implode('، ');

                // Snapshot the pre-fail state so MyAccounts can offer a
                // 5-second undo. "Wrong data" is the one action here where
                // a mistaken click is common (checkbox slip) AND fully
                // reversible — nothing has been sent to the customer yet,
                // no proof has changed hands, the account is still in
                // this employee's queue.
                $previous = [
                    'assignment_id'   => $this->assignment->id,
                    'previous_status' => $this->assignment->status,
                    'previous_notes'  => $this->assignment->notes,
                    'expires_at'      => now()->addSeconds(5)->timestamp,
                ];

                DB::transaction(function () use ($labels): void {
                    $this->assignment->update([
                        'status'       => AccountAssignment::STATUS_FAILED,
                        'completed_at' => now(),
                        'notes'        => 'بيانات خطأ: '.$labels,
                    ]);
                    $this->logAction('fail', $this->assignment->account);
                });

                session()->put('undo_fail', $previous);

                Notification::make()->warning()->title('سُجِّل الفشل — '.$labels)->send();
                $this->redirect(MyAccounts::getUrl());
            });
    }

    /**
     * Per-user (hourly + daily) and per-IP (hourly) throttle for sensitive
     * actions. Complements the assignment-scoped TOTP allowance which only
     * counts on a single account — this one catches an employee farming
     * many accounts. On breach: notify the user AND raise a high-severity
     * alert once per hour so the supervisor can react.
     */
    protected function guardRateLimit(string $action): bool
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $limiter = app(RevealRateLimiter::class);

        $reason = $limiter->check($user, (string) request()->ip(), $action);
        if ($reason === null) {
            return true;
        }

        $limiter->raiseAlert($user, $action, $reason, $this->assignment->account_id ?? null);

        Notification::make()
            ->danger()
            ->title('تم إيقاف العملية مؤقتاً')
            ->body($reason)
            ->send();

        return false;
    }

    protected function logAction(string $action, Account $account): void
    {
        RevealLog::create([
            'tenant_id'          => $account->tenant_id,
            'user_id'            => (int) auth()->id(),
            'account_id'         => $account->id,
            'action'             => $action,
            'ip'                 => request()->ip(),
            'user_agent'         => substr((string) request()->userAgent(), 0, 500),
            'device_fingerprint' => request()->cookie('fc_fp'),
            'created_at'         => now(),
        ]);
    }
}
