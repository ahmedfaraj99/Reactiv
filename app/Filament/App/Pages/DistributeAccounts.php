<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\AccountResource;
use App\Models\Account;
use App\Models\AccountAssignment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * Fast bulk distribution for managers/supervisors handling
 * thousands of accounts at once — what you see is what happens:
 *
 *  1. pick the kind (matches / activation only),
 *  2. pick a client from the stock cards (shown by code, never by name),
 *  3. pick employees and a count for each.
 *
 * Each employee only ever holds ONE client's accounts at a time — mixing
 * customers on one console risks a chain lock — so employees still busy
 * with another client are shown as such and can't be picked. Counts are
 * checked against that client's stock up front, so nobody silently gets
 * fewer than the manager typed.
 */
class DistributeAccounts extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?string $navigationLabel = 'توزيع الحسابات';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.app.pages.distribute-accounts';

    /** @var array<string,mixed> */
    public ?array $data = [];

    /** Per-request memo of employeeClients(). @var array<int,array<string,int>>|null */
    protected ?array $employeeClientsMemo = null;

    /** Per-request memo of clientStock(), keyed by kind. @var array<string,array> */
    protected array $clientStockMemo = [];

    /** Per-request memo of releasable(). @var array<int,array{unopened:int,stuck:int,stuck_since:?string}>|null */
    protected ?array $releasableMemo = null;

    /**
     * The owner only uploads batches (see AccountResource's import
     * action) — distribution to employees is entirely the manager's
     * and their supervisors' job.
     */
    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isManager() || $u->isSupervisor());
    }

    /**
     * The pool of accounts a manager or supervisor may draw from when
     * distributing — always scoped to the manager who owns the batch
     * (a manager's own uploads, or the batch belonging to a
     * supervisor's manager), never the whole tenant.
     */
    private static function scopedManagerId(): ?int
    {
        $u = auth()->user();

        if ($u?->isManager()) {
            return $u->id;
        }
        if ($u?->isSupervisor()) {
            return $u->office?->manager_id;
        }

        return null;
    }

    public function getTitle(): string|Htmlable
    {
        return 'توزيع الحسابات';
    }

    public function mount(): void
    {
        $this->form->fill([
            'matches_type' => 'matches',
            'client'       => array_key_first($this->clientStock('matches')),
        ]);
    }

    /** Form key for a client — ToggleButtons need string keys, and null is a real group. */
    private static function clientKey(?int $clientId): string
    {
        return $clientId === null ? 'none' : 'c'.$clientId;
    }

    /** @return array{0: bool, 1: ?int} [valid, clientId] */
    private static function parseClientKey(?string $key): array
    {
        if ($key === 'none') {
            return [true, null];
        }
        if (is_string($key) && preg_match('/^c(\d+)$/', $key, $m)) {
            return [true, (int) $m[1]];
        }

        return [false, null];
    }

    private function poolQuery(string $type)
    {
        return Account::query()
            ->where('tenant_id', filament()->getTenant()->id)
            ->where('manager_id', self::scopedManagerId())
            ->where('status', 'available')
            ->ofMatchesType($type);
    }

    /**
     * Available stock of one kind per client, the client whose accounts
     * have waited longest first.
     *
     * @return array<string,array{client_id: ?int, label: string, count: int}>
     */
    public function clientStock(?string $type = null): array
    {
        $type ??= $this->selectedMatchesType();

        return $this->clientStockMemo[$type] ??= $this->poolQuery($type)
            ->selectRaw('client_id, count(*) as cnt, min(created_at) as oldest')
            ->groupBy('client_id')
            ->orderBy('oldest')
            ->get()
            ->mapWithKeys(function ($row): array {
                $id = $row->client_id === null ? null : (int) $row->client_id;

                return [self::clientKey($id) => [
                    'client_id' => $id,
                    'label'     => AccountResource::clientLabel($id),
                    'count'     => (int) $row->cnt,
                ]];
            })
            ->all();
    }

    /**
     * Open (pending / in-progress) accounts per client for every employee
     * this user may distribute to: employee_id => [client key => count].
     *
     * @return array<int,array<string,int>>
     */
    public function employeeClients(): array
    {
        if ($this->employeeClientsMemo !== null) {
            return $this->employeeClientsMemo;
        }

        $map = [];
        AccountAssignment::query()
            ->join('accounts', 'accounts.id', '=', 'account_assignments.account_id')
            ->whereIn('account_assignments.employee_id', array_keys(AccountResource::employeeOptions()))
            ->whereIn('account_assignments.status', AccountResource::CLIENT_LOCK_STATUSES)
            ->whereNull('accounts.deleted_at')
            ->selectRaw('account_assignments.employee_id, accounts.client_id, count(*) as cnt')
            ->groupBy('account_assignments.employee_id', 'accounts.client_id')
            ->get()
            ->each(function ($row) use (&$map): void {
                $clientId = $row->client_id === null ? null : (int) $row->client_id;
                $map[(int) $row->employee_id][self::clientKey($clientId)] = (int) $row->cnt;
            });

        return $this->employeeClientsMemo = $map;
    }

    /** Free, or already working on this same client. */
    private function canReceive(int $employeeId, string $clientKey): bool
    {
        $open = $this->employeeClients()[$employeeId] ?? [];

        return $open === [] || array_key_exists($clientKey, $open);
    }

    private function employeeStatus(int $employeeId): string
    {
        $open = $this->employeeClients()[$employeeId] ?? [];
        if ($open === []) {
            return 'حر';
        }

        return 'يعمل على '.collect($open)
            ->map(function (int $cnt, string $key): string {
                [, $id] = self::parseClientKey($key);
                return AccountResource::clientLabel($id)." ({$cnt})";
            })
            ->implode('، ');
    }

    /**
     * What "release" would send back to the stock, per employee.
     *
     * @return array<int,array{unopened:int,stuck:int,stuck_since:?string}>
     */
    public function releasable(): array
    {
        return $this->releasableMemo ??= AccountResource::releasableAssignments(array_keys(AccountResource::employeeOptions()))
            ->selectRaw('employee_id,
                sum(case when credentials_revealed_at is null then 1 else 0 end) as unopened,
                sum(case when credentials_revealed_at is not null then 1 else 0 end) as stuck,
                min(credentials_revealed_at) as stuck_since')
            ->groupBy('employee_id')
            ->get()
            ->mapWithKeys(fn ($r): array => [(int) $r->employee_id => [
                'unopened'    => (int) $r->unopened,
                'stuck'       => (int) $r->stuck,
                'stuck_since' => $r->stuck > 0 ? (string) $r->stuck_since : null,
            ]])
            ->all();
    }

    private static function releasableText(array $r): string
    {
        $parts = [];
        if ($r['unopened'] > 0) {
            $parts[] = "{$r['unopened']} غير مفتوح";
        }
        if ($r['stuck'] > 0) {
            $parts[] = "{$r['stuck']} عالق منذ ".\Illuminate\Support\Carbon::parse($r['stuck_since'])->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE);
        }

        return implode('، ', $parts);
    }

    /**
     * Employees who can't take the picked client right now, with what
     * releasing them would send back.
     *
     * @return array<int,array{id:int,name:string,status:string,releasable:string,can_release:bool}>
     */
    public function blockedEmployees(): array
    {
        $clientKey = (string) ($this->data['client'] ?? '');
        if ($clientKey === '') {
            return [];
        }

        return collect(AccountResource::employeeOptions())
            ->reject(fn (string $name, int $id): bool => $this->canReceive($id, $clientKey))
            ->map(function (string $name, int $id): array {
                $r = $this->releasable()[$id] ?? null;

                return [
                    'id'          => $id,
                    'name'        => $name,
                    'status'      => $this->employeeStatus($id),
                    'releasable'  => $r !== null ? self::releasableText($r) : '',
                    'can_release' => $r !== null,
                ];
            })
            ->values()
            ->all();
    }

    private function resetMemos(): void
    {
        $this->employeeClientsMemo = null;
        $this->clientStockMemo = [];
        $this->releasableMemo = null;
    }

    private function releaseNotice(array $done): void
    {
        $total = $done['unopened'] + $done['stuck'];

        Notification::make()
            ->status($total > 0 ? 'success' : 'warning')
            ->title($total > 0 ? "أُرجع {$total} حساباً إلى المخزون" : 'لا يوجد ما يُرجع')
            ->body($total > 0 ? "{$done['unopened']} غير مفتوح، {$done['stuck']} عالق (مفتوح منذ أكثر من ".AccountResource::STUCK_AFTER_HOURS.' ساعة).' : null)
            ->send();
    }

    public function releaseEmployeeAction(): Action
    {
        return Action::make('releaseEmployee')
            ->label('تحرير')
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('warning')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'تحرير '.(User::find((int) ($arguments['employee'] ?? 0))?->name ?? 'الموظف'))
            ->modalDescription(function (array $arguments): string {
                $r = $this->releasable()[(int) ($arguments['employee'] ?? 0)] ?? null;

                return $r === null
                    ? 'لا يوجد لديه ما يُرجع.'
                    : 'سيرجع إلى المخزون: '.self::releasableText($r).'. الحسابات المفتوحة خلال آخر '.AccountResource::STUCK_AFTER_HOURS.' ساعة والمُعادة لتصحيح الإثبات تبقى معه.';
            })
            ->action(function (array $arguments): void {
                $this->releaseNotice(AccountResource::releaseToStock([(int) ($arguments['employee'] ?? 0)]));
                $this->resetMemos();
            });
    }

    public function releaseAllAction(): Action
    {
        return Action::make('releaseAll')
            ->label('تحرير كل الموظفين')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->outlined()
            ->requiresConfirmation()
            ->modalDescription(function (): string {
                $unopened = array_sum(array_column($this->releasable(), 'unopened'));
                $stuck = array_sum(array_column($this->releasable(), 'stuck'));

                return "سيرجع إلى المخزون من كل الموظفين: {$unopened} غير مفتوح، و{$stuck} عالق (مفتوح منذ أكثر من "
                    .AccountResource::STUCK_AFTER_HOURS.' ساعة ولم يكتمل). الحسابات المفتوحة حديثاً والمُعادة لتصحيح الإثبات تبقى مع أصحابها.';
            })
            ->action(function (): void {
                $this->releaseNotice(AccountResource::releaseToStock(array_keys(AccountResource::employeeOptions())));
                $this->resetMemos();
            });
    }

    public function form(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        return $form
            ->schema([
                ToggleButtons::make('matches_type')
                    ->label('1) نوع الحسابات')
                    ->options(fn (): array => [
                        'matches'    => 'حسابات بمباريات ('.$this->availableCount('matches').' متاح)',
                        'activation' => 'تفعيل فقط ('.$this->availableCount('activation').' متاح)',
                    ])
                    ->icons([
                        'matches'    => 'heroicon-m-trophy',
                        'activation' => 'heroicon-m-check-badge',
                    ])
                    ->colors([
                        'matches'    => 'purple',
                        'activation' => 'success',
                    ])
                    ->inline()
                    ->required()
                    ->live()
                    ->default('matches')
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $set('client', array_key_first($this->clientStock($state === 'activation' ? 'activation' : 'matches')));
                        $set('employee_ids', []);
                        $set('rows', []);
                    }),

                ToggleButtons::make('client')
                    ->label('2) اختر العميل من المخزون')
                    ->helperText('الأقدم انتظاراً أولاً. يُعرض كل عميل برمزه.')
                    ->options(fn (): array => collect($this->clientStock())
                        ->map(fn (array $s): string => "{$s['label']} — {$s['count']} متاح")
                        ->all())
                    ->inline()
                    ->required()
                    ->live()
                    ->visible(fn (): bool => $this->clientStock() !== [])
                    ->afterStateUpdated(function (Set $set): void {
                        // Who may receive depends on the client — start over.
                        $set('employee_ids', []);
                        $set('rows', []);
                    }),

                Placeholder::make('empty_stock')
                    ->hiddenLabel()
                    ->content('لا توجد حسابات متاحة من هذا النوع حالياً.')
                    ->visible(fn (): bool => $this->clientStock() === []),

                Select::make('employee_ids')
                    ->label('3) اختر الموظفين')
                    ->helperText('الموظف الذي يعمل على عميل آخر يظهر معطّلاً حتى ينهي حساباته — لمنع خلط العملاء على جهاز واحد.')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->options(fn (): array => collect(AccountResource::employeeOptions())
                        ->map(fn (string $name, int $id): string => "{$name} — ".$this->employeeStatus($id))
                        ->all())
                    ->disableOptionWhen(fn (string $value, Get $get): bool => ! $this->canReceive((int) $value, (string) $get('client')))
                    ->live()
                    ->visible(fn (Get $get): bool => filled($get('client')) && $this->clientStock() !== [])
                    ->afterStateUpdated(function (?array $state, Set $set, Get $get): void {
                        $existing = collect($get('rows') ?? [])->keyBy('employee_id');
                        $ids = $state ?? [];

                        $set('rows', collect($ids)->map(fn ($id) => [
                            'employee_id' => $id,
                            'count'       => $existing->get($id)['count'] ?? 1,
                        ])->values()->all());
                    }),

                Repeater::make('rows')
                    ->label('الأعداد لكل موظف')
                    ->schema([
                        Hidden::make('employee_id'),

                        TextInput::make('count')
                            ->label('العدد')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->itemLabel(fn (array $state): ?string => User::find($state['employee_id'] ?? null)?->name)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columns(1)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => filled($get('employee_ids'))),
            ])
            ->statePath('data');
    }

    /** Available accounts of one kind ('matches' / 'activation'), or both when null. */
    public function availableCount(?string $type = null): int
    {
        return Account::query()
            ->where('tenant_id', filament()->getTenant()->id)
            ->where('manager_id', self::scopedManagerId())
            ->where('status', 'available')
            ->ofMatchesType($type)
            ->count();
    }

    /** The kind picked on the form — falls back to matches, the form's default. */
    public function selectedMatchesType(): string
    {
        return ($this->data['matches_type'] ?? null) === 'activation' ? 'activation' : 'matches';
    }

    public function selectedMatchesTypeLabel(): string
    {
        return $this->selectedMatchesType() === 'matches' ? 'بمباريات' : 'تفعيل فقط';
    }

    /** Stock of the client picked on the form, 0 when none / gone. */
    private function selectedClientCount(): int
    {
        return $this->clientStock()[$this->data['client'] ?? '']['count'] ?? 0;
    }

    public function equalizeAction(): Action
    {
        return Action::make('equalize')
            ->label('وزّع رصيد العميل بالتساوي على المُختارين')
            ->color('gray')
            ->action(function (): void {
                $rows = $this->form->getState()['rows'] ?? [];
                $employeeCount = count($rows);

                if ($employeeCount === 0) {
                    Notification::make()->warning()->title('اختر موظفاً واحداً على الأقل أولاً')->send();
                    return;
                }

                $available = $this->selectedClientCount();
                $share = intdiv($available, $employeeCount);
                $remainder = $available % $employeeCount;

                foreach ($rows as $i => $row) {
                    $rows[$i]['count'] = $share + ($i < $remainder ? 1 : 0);
                }

                $this->form->fill([
                    'matches_type' => $this->selectedMatchesType(),
                    'client'       => $this->data['client'] ?? null,
                    'employee_ids' => array_column($rows, 'employee_id'),
                    'rows'         => $rows,
                ]);
            });
    }

    public function distributeAction(): Action
    {
        return Action::make('distribute')
            ->label('توزيع الآن')
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(function (): string {
                $total = array_sum(array_map('intval', array_column($this->data['rows'] ?? [], 'count')));
                [, $clientId] = self::parseClientKey($this->data['client'] ?? null);

                return "سيُخصَّص {$total} حساباً من حسابات «".$this->selectedMatchesTypeLabel().'» للعميل '
                    .AccountResource::clientLabel($clientId).' (الأقدم رفعاً أولاً)، كل موظف بالعدد المكتوب أمامه.';
            })
            ->action(function (): void {
                $state = $this->form->getState();
                $rows = $state['rows'] ?? [];

                if (empty($rows)) {
                    Notification::make()->warning()->title('اختر موظفاً واحداً على الأقل')->send();
                    return;
                }

                $type = $state['matches_type'] === 'activation' ? 'activation' : 'matches';
                $typeLabel = $type === 'matches' ? 'بمباريات' : 'تفعيل فقط';
                $clientKey = (string) ($state['client'] ?? '');
                [$valid, $clientId] = self::parseClientKey($clientKey);
                $label = AccountResource::clientLabel($clientId);

                if (! $valid) {
                    Notification::make()->warning()->title('اختر العميل أولاً')->send();
                    return;
                }

                $totalRequested = array_sum(array_map('intval', array_column($rows, 'count')));
                $available = $this->clientStock($type)[$clientKey]['count'] ?? 0;

                if ($totalRequested > $available) {
                    Notification::make()
                        ->danger()
                        ->title('العدد المطلوب أكبر من رصيد العميل')
                        ->body("طلبت {$totalRequested}، والمتاح للعميل {$label} من حسابات «{$typeLabel}» {$available} فقط.")
                        ->send();
                    return;
                }

                // Re-check on the server — the page may be stale, and the
                // disabled options are only a hint to the browser.
                $this->employeeClientsMemo = null;
                $blocked = collect($rows)
                    ->map(fn (array $row): int => (int) $row['employee_id'])
                    ->reject(fn (int $id): bool => $this->canReceive($id, $clientKey))
                    ->map(fn (int $id): string => (User::find($id)?->name ?? '#'.$id).' ('.$this->employeeStatus($id).')');

                if ($blocked->isNotEmpty()) {
                    Notification::make()
                        ->danger()
                        ->title('موظفون يعملون على عميل آخر')
                        ->body('لا يمكن إعطاؤهم حسابات '.$label.' حتى ينهوا ما لديهم: '.$blocked->implode('، '))
                        ->send();
                    return;
                }

                $summary = [];

                DB::transaction(function () use ($rows, $type, $clientId, &$summary): void {
                    foreach ($rows as $row) {
                        $employeeId = (int) $row['employee_id'];

                        $accounts = $this->poolQuery($type)
                            ->when(
                                $clientId === null,
                                fn ($q) => $q->whereNull('client_id'),
                                fn ($q) => $q->where('client_id', $clientId),
                            )
                            ->orderBy('created_at')
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->limit((int) $row['count'])
                            ->get();

                        AccountResource::assignAccounts($accounts, $employeeId);

                        $summary[] = (User::find($employeeId)?->name ?? '#'.$employeeId).': '.$accounts->count();
                    }
                });

                Notification::make()
                    ->success()
                    ->title("اكتمل توزيع حسابات «{$typeLabel}» للعميل {$label}")
                    ->body(implode(' — ', $summary))
                    ->send();

                // Stay on the same kind — managers usually hand out one
                // kind in several rounds. Move on to the oldest client left.
                $this->resetMemos();
                $this->form->fill([
                    'matches_type' => $type,
                    'client'       => array_key_exists($clientKey, $this->clientStock($type)) ? $clientKey : array_key_first($this->clientStock($type)),
                ]);
            });
    }
}
