<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Enums\UserRole;
use App\Filament\App\Resources\AssignmentReviewResource\Pages;
use App\Models\AccountAssignment;
use App\Models\RevealLog;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where a manager reviews the proof-of-completion image an employee
 * submits after activating an account, and approves or rejects it.
 * Approval is what finally flips Account.status to 'activated' — see
 * Activation.php's completeAction() for the employee-side submission.
 */
class AssignmentReviewResource extends Resource
{
    protected static ?string $model = AccountAssignment::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'مراجعة الإثباتات';

    protected static ?string $modelLabel = 'إثبات';

    protected static ?string $pluralModelLabel = 'مراجعة الإثباتات';

    protected static ?int $navigationSort = 4;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    /**
     * The rejection reasons a supervisor picks with a single tap instead
     * of typing out. Standardised phrasing makes later frequency reports
     * meaningful ("60% of rejections are 'أرباح غير ظاهرة'" is a signal
     * either about the customer expectations or about employee training).
     * Free-text tweaking is still allowed in the textarea below the chips.
     */
    public const REJECTION_CHIPS = [
        'no_rewards'   => 'أرباح المباريات غير ظاهرة في الصورة',
        'no_mvp'       => 'الـ MVP = ٠',
        'one_match'    => 'مباراة واحدة فقط بدلاً من العدد المطلوب',
        'not_logged'   => 'الحساب غير مسجّل دخول',
        'blurry'       => 'الصورة ضبابية / غير مقروءة',
        'wrong_screen' => 'شاشة خاطئة (ليست شاشة نتائج المباراة)',
        'browser_shot' => 'Screenshot من المتصفح بدل صورة كاميرا للتلفزيون',
        'wrong_game'   => 'اللعبة الظاهرة ليست FC/FIFA',
    ];

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('status', AccountAssignment::STATUS_AWAITING_REVIEW)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Scope by tier:
     *  - Owner: whole tenant.
     *  - Manager: submissions from employees in the offices they manage.
     *  - Supervisor: submissions from employees in their single office.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['account', 'employee', 'supervisor', 'reviewer']);
        $u = auth()->user();

        if ($u?->isManager()) {
            $employeeIds = User::query()
                ->whereIn('office_id', $u->managedOffices()->pluck('id'))
                ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value))
                ->pluck('id');
            $query->whereIn('employee_id', $employeeIds);
        } elseif ($u?->isSupervisor()) {
            $employeeIds = User::query()
                ->where('office_id', $u->office_id)
                ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value))
                ->pluck('id');
            $query->whereIn('employee_id', $employeeIds);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('account_id')
                    ->label('الحساب')
                    ->formatStateUsing(fn ($state): string => '#'.$state),

                Tables\Columns\TextColumn::make('employee.name')
                    ->label('الموظف')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AccountAssignment::STATUS_AWAITING_REVIEW => 'warning',
                        AccountAssignment::STATUS_COMPLETED        => 'success',
                        AccountAssignment::STATUS_IN_PROGRESS      => 'gray',
                        AccountAssignment::STATUS_FAILED           => 'danger',
                        default                                    => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        AccountAssignment::STATUS_AWAITING_REVIEW => 'بانتظار المراجعة',
                        AccountAssignment::STATUS_COMPLETED        => 'مُعتمَد',
                        AccountAssignment::STATUS_IN_PROGRESS      => 'أُعيد للموظف',
                        AccountAssignment::STATUS_FAILED           => 'فشل',
                        default                                    => $state,
                    }),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('أُرسل في')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reviewer.name')
                    ->label('راجعه')
                    ->placeholder('—'),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        AccountAssignment::STATUS_AWAITING_REVIEW => 'بانتظار المراجعة',
                        AccountAssignment::STATUS_COMPLETED        => 'مُعتمَد',
                        AccountAssignment::STATUS_IN_PROGRESS      => 'أُعيد للموظف',
                        AccountAssignment::STATUS_FAILED           => 'فشل',
                    ])
                    ->default(AccountAssignment::STATUS_AWAITING_REVIEW),
            ])
            ->actions([
                Tables\Actions\Action::make('view_proof')
                    ->label('عرض الإثبات')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->url(fn (AccountAssignment $r): string => route('proof.show', $r))
                    ->openUrlInNewTab()
                    ->visible(fn (AccountAssignment $r): bool => $r->proof_path !== null),

                // Full timeline of every state change on this assignment,
                // with absolute time + delta from the previous step. Useful
                // when a review takes long and the supervisor needs to see
                // whether the employee genuinely spent that time working, or
                // sat idle between reveal and TOTP. Renders as a read-only
                // modal — no side effects.
                Tables\Actions\Action::make('timeline')
                    ->label('خط زمني')
                    ->icon('heroicon-o-clock')
                    ->color('info')
                    ->modalHeading(fn (AccountAssignment $r): string => "خط زمني: حساب #{$r->account_id}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->modalContent(fn (AccountAssignment $r) =>
                        view('filament.app.partials.assignment-timeline', ['assignment' => $r])),

                Tables\Actions\Action::make('approve')
                    ->label('اعتماد')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (AccountAssignment $r): bool => $r->status === AccountAssignment::STATUS_AWAITING_REVIEW)
                    ->requiresConfirmation()
                    ->action(function (AccountAssignment $record): void {
                        DB::transaction(function () use ($record): void {
                            $record->update([
                                'status'       => AccountAssignment::STATUS_COMPLETED,
                                'reviewed_by'  => auth()->id(),
                                'reviewed_at'  => now(),
                                'completed_at' => now(),
                            ]);
                            $record->account->update([
                                'status'       => 'activated',
                                'activated_at' => now(),
                            ]);
                            self::logAction($record, 'approve');
                        });

                        Notification::make()->success()->title('تم اعتماد الإثبات')->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AccountAssignment $r): bool => $r->status === AccountAssignment::STATUS_AWAITING_REVIEW)
                    ->form([
                        // Quick-pick chips for the most common rejection
                        // reasons — supervisors reviewing dozens of proofs
                        // a day type the same handful of phrases over and
                        // over. Picking one fills the free-text field so
                        // the supervisor can still tweak/append before
                        // sending. Standardised phrasing also makes any
                        // later reason-frequency reporting meaningful.
                        Forms\Components\CheckboxList::make('rejection_chips')
                            ->label('أسباب شائعة')
                            ->options(self::REJECTION_CHIPS)
                            ->columns(2)
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?array $state): void {
                                if (empty($state)) {
                                    return;
                                }
                                $set('rejection_reason', implode('، ', array_map(
                                    fn (string $key): string => self::REJECTION_CHIPS[$key] ?? $key,
                                    $state,
                                )));
                            }),

                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('سبب الرفض')
                            ->helperText('اختر من الشائعة أعلاه ثم عدّل عند الحاجة، أو اكتب سبباً حراً.')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (AccountAssignment $record, array $data): void {
                        DB::transaction(function () use ($record, $data): void {
                            $record->update([
                                'status'           => AccountAssignment::STATUS_IN_PROGRESS,
                                'reviewed_by'      => auth()->id(),
                                'reviewed_at'      => now(),
                                'rejection_reason' => (string) $data['rejection_reason'],
                            ]);
                            self::logAction($record, 'reject');
                        });

                        Notification::make()->warning()->title('أُعيد الحساب للموظف')->send();
                    }),
            ]);
    }

    private static function logAction(AccountAssignment $assignment, string $action): void
    {
        RevealLog::create([
            'tenant_id'  => $assignment->tenant_id,
            'user_id'    => (int) auth()->id(),
            'account_id' => $assignment->account_id,
            'action'     => $action,
            'ip'         => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssignmentReviews::route('/'),
        ];
    }
}
