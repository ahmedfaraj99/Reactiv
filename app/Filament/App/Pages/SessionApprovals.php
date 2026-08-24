<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\SessionRequest;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Approvers' inbox. A manager sees pending + active session requests
 * for all offices in the tenant; a supervisor sees only their own
 * office. Both can approve/deny/revoke. Polls every 10s so a phone
 * on the manager's desk shows new requests without a page refresh.
 */
class SessionApprovals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.app.pages.session-approvals';

    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'موافقات الدخول';
    protected static ?string $title = 'موافقات دخول الموظفين';
    protected static ?int $navigationSort = -50;

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

    protected function getViewData(): array
    {
        return [
            'pendingCount' => $this->scopedQuery()->pending()->count(),
        ];
    }

    protected function scopedQuery(): Builder
    {
        /** @var User $u */
        $u = auth()->user();
        $q = SessionRequest::query()->where('tenant_id', $u->tenant_id);

        // Supervisor sees only their office. Manager/owner see the
        // whole tenant. If we ever add "team leads" scoped narrower
        // than a supervisor, add the branch here.
        if ($u->isSupervisor()) {
            $q->where('office_id', $u->office_id);
        }

        return $q;
    }

    /**
     * Refresh the approvals table on any session-request state change
     * broadcast to this scope. Managers/owner subscribe to the tenant
     * channel; supervisors to their office channel. Livewire's
     * `$refresh` on echo events rebuilds the table without a poll.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        /** @var User $u */
        $u = auth()->user();
        $listeners = [];

        if ($u->isTenantOwner() || $u->isManager()) {
            $listeners['echo-private:tenant.'.$u->tenant_id.'.approvals,.session-request.changed'] = '$refresh';
        }

        if ($u->isSupervisor() && $u->office_id !== null) {
            $listeners['echo-private:office.'.$u->office_id.'.approvals,.session-request.changed'] = '$refresh';
        }

        return $listeners;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->scopedQuery()->with(['user', 'office', 'decider']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('الموظف')->searchable(),
                TextColumn::make('office.name')->label('المكتب')->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge()->colors([
                    'warning' => SessionRequest::STATUS_PENDING,
                    'success' => SessionRequest::STATUS_APPROVED,
                    'danger'  => SessionRequest::STATUS_DENIED,
                    'gray'    => [SessionRequest::STATUS_REVOKED, SessionRequest::STATUS_EXPIRED],
                ])->formatStateUsing(fn (string $state): string => match ($state) {
                    SessionRequest::STATUS_PENDING  => 'بانتظار',
                    SessionRequest::STATUS_APPROVED => 'موافَق',
                    SessionRequest::STATUS_DENIED   => 'مرفوض',
                    SessionRequest::STATUS_REVOKED  => 'ملغى',
                    SessionRequest::STATUS_EXPIRED  => 'منتهي',
                    default => $state,
                }),
                TextColumn::make('requested_at')->label('طُلب')->since(),
                TextColumn::make('expires_at')->label('ينتهي')->since()->placeholder('—'),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('decider.name')->label('قرَّر')->toggleable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    SessionRequest::STATUS_PENDING  => 'بانتظار',
                    SessionRequest::STATUS_APPROVED => 'موافَق',
                    SessionRequest::STATUS_DENIED   => 'مرفوض',
                    SessionRequest::STATUS_REVOKED  => 'ملغى',
                    SessionRequest::STATUS_EXPIRED  => 'منتهي',
                ])->default(SessionRequest::STATUS_PENDING),
            ])
            ->headerActions([
                // One-click blanket approval for every pending request in
                // the supervisor's scope. Start-of-shift, a supervisor
                // typically has 5–10 employees waiting and rejecting is
                // the rare case, so bulk-approving with a per-row escape
                // hatch (deny still available individually) is the right
                // shape. Count is computed at click time from the same
                // scoped query the table uses, so a supervisor never
                // approves outside their office.
                TableAction::make('approveAllPending')
                    ->label(function (): string {
                        $count = $this->scopedQuery()->pending()->count();
                        return $count > 0 ? "موافقة على الكل ({$count})" : 'موافقة على الكل';
                    })
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (): bool => $this->scopedQuery()->pending()->exists())
                    ->requiresConfirmation()
                    ->modalHeading('موافقة على كل الطلبات المعلقة')
                    ->modalDescription('سيتم اعتماد كل الطلبات المعلقة في نطاقك مرة واحدة. للحالات الاستثنائية استخدم زر "رفض" الفردي.')
                    ->action(function (): void {
                        $this->approveAllPending();
                    }),
            ])
            ->actions([
                TableAction::make('approve')
                    ->label('موافقة')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (SessionRequest $r): bool => $r->status === SessionRequest::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(fn (SessionRequest $r) => $this->approve($r)),
                TableAction::make('deny')
                    ->label('رفض')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (SessionRequest $r): bool => $r->status === SessionRequest::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(fn (SessionRequest $r) => $this->deny($r)),
                TableAction::make('revoke')
                    ->label('إنهاء الجلسة')->icon('heroicon-o-power')->color('danger')
                    ->visible(fn (SessionRequest $r): bool => $r->isCurrentlyValid())
                    ->requiresConfirmation()
                    ->modalDescription('سيتم فصل الموظف فوراً وإعادته لشاشة الانتظار.')
                    ->action(fn (SessionRequest $r) => $this->revoke($r)),
                TableAction::make('extend')
                    ->label('تمديد')->icon('heroicon-o-clock')->color('warning')
                    ->visible(fn (SessionRequest $r): bool => $r->isCurrentlyValid())
                    ->action(fn (SessionRequest $r) => $this->extend($r)),
            ]);
    }

    protected function approve(SessionRequest $r): void
    {
        $this->assertCanDecide($r);

        $hours = (int) config('fc27ac.session_approval.duration_hours', 4);
        $r->update([
            'status'      => SessionRequest::STATUS_APPROVED,
            'decided_at'  => now(),
            'decided_by'  => auth()->id(),
            'expires_at'  => now()->addHours($hours),
        ]);

        \App\Events\SessionRequestChanged::dispatch((int) $r->tenant_id, (int) $r->office_id);

        Notification::make()->success()->title('تمت الموافقة')->body("جلسة {$r->user->name} صالحة لمدة {$hours} ساعة.")->send();
    }

    /**
     * Bulk-approve every pending request currently in this user's scope.
     * Scoping is delegated to scopedQuery(), so a supervisor's "all"
     * means only their own office. A single UPDATE stamps status, decider,
     * and expiry atomically — no per-row loop, no race with an incoming
     * new request that arrives mid-loop.
     */
    protected function approveAllPending(): void
    {
        $hours = (int) config('fc27ac.session_approval.duration_hours', 4);

        $count = $this->scopedQuery()
            ->pending()
            ->update([
                'status'     => SessionRequest::STATUS_APPROVED,
                'decided_at' => now(),
                'decided_by' => auth()->id(),
                'expires_at' => now()->addHours($hours),
            ]);

        // Fan-out on the tenant channel only — every subscriber (managers
        // and the owner) refreshes. Supervisors do not receive tenant-wide
        // events since bulk-approve fires from their own scope which is
        // already covered by them being on the office channel too; sending
        // a per-office event too would cause them to double-refresh.
        /** @var \App\Models\User $u */
        $u = auth()->user();
        \App\Events\SessionRequestChanged::dispatch(
            (int) $u->tenant_id,
            (int) ($u->office_id ?? 0),
        );

        Notification::make()
            ->success()
            ->title("تمت الموافقة على {$count} طلب")
            ->body("كل الجلسات صالحة لمدة {$hours} ساعة.")
            ->send();
    }

    protected function deny(SessionRequest $r): void
    {
        $this->assertCanDecide($r);

        $r->update([
            'status'     => SessionRequest::STATUS_DENIED,
            'decided_at' => now(),
            'decided_by' => auth()->id(),
        ]);

        \App\Events\SessionRequestChanged::dispatch((int) $r->tenant_id, (int) $r->office_id);

        Notification::make()->warning()->title('تم الرفض')->send();
    }

    protected function revoke(SessionRequest $r): void
    {
        $this->assertCanDecide($r);

        $r->update([
            'status'      => SessionRequest::STATUS_REVOKED,
            'revoked_at'  => now(),
            'decided_by'  => auth()->id(),
        ]);

        \App\Events\SessionRequestChanged::dispatch((int) $r->tenant_id, (int) $r->office_id);

        Notification::make()->success()->title('تم إنهاء الجلسة')->send();
    }

    protected function extend(SessionRequest $r): void
    {
        $this->assertCanDecide($r);

        $hours = (int) config('fc27ac.session_approval.duration_hours', 4);
        $r->update(['expires_at' => now()->addHours($hours)]);

        Notification::make()->success()->title("مُدِّدت {$hours} ساعة إضافية")->send();
    }

    /**
     * A supervisor cannot decide on another office's requests, and no
     * one can decide on a request from a different tenant. Enforced
     * server-side because the UI filter alone is not a security control.
     */
    protected function assertCanDecide(SessionRequest $r): void
    {
        /** @var User $u */
        $u = auth()->user();
        abort_unless($r->tenant_id === $u->tenant_id, 403);
        if ($u->isSupervisor()) {
            abort_unless($r->office_id === $u->office_id, 403);
        }
    }
}
