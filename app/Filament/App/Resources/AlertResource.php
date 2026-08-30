<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Enums\AlertSeverity;
use App\Enums\UserRole;
use App\Filament\App\Resources\AlertResource\Pages;
use App\Models\Alert;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use App\Enums\AlertType;

class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'التنبيهات';

    protected static ?string $modelLabel = 'تنبيه';

    protected static ?string $pluralModelLabel = 'التنبيهات';

    protected static ?int $navigationSort = 11;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

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
        $count = static::getEloquentQuery()->where('resolved', false)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Scope by tier:
     *  - Owner: whole tenant.
     *  - Manager: alerts on employees in the offices they manage.
     *  - Supervisor: alerts on employees in their single office.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $u = auth()->user();

        if ($u?->isManager()) {
            $employeeIds = User::query()
                ->whereIn('office_id', $u->managedOffices()->pluck('id'))
                ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value))
                ->pluck('id');
            $query->whereIn('user_id', $employeeIds);
        } elseif ($u?->isSupervisor()) {
            $employeeIds = User::query()
                ->where('office_id', $u->office_id)
                ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Employee->value))
                ->pluck('id');
            $query->whereIn('user_id', $employeeIds);
        }

        return $query->with(['user', 'account']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('severity')
                    ->label('الخطورة')
                    ->badge()
                    ->color(fn (AlertSeverity $state): string => $state->filamentColor())
                    ->formatStateUsing(fn (AlertSeverity $state): string => $state->label()),

                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->formatStateUsing(fn (AlertType $state): string => $state->label()),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('الموظف')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('account_id')
                    ->label('الحساب')
                    ->formatStateUsing(fn ($state): string => $state ? '#'.$state : '—'),

                Tables\Columns\TextColumn::make('message')
                    ->label('التفاصيل')
                    ->wrap(),

                Tables\Columns\IconColumn::make('resolved')
                    ->label('حُلَّ')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('resolved')
                    ->label('الحالة')
                    ->placeholder('الكل')
                    ->trueLabel('محلولة فقط')
                    ->falseLabel('غير محلولة فقط')
                    ->default(false),

                Tables\Filters\SelectFilter::make('severity')
                    ->label('الخطورة')
                    ->options(AlertSeverity::options()),

                Tables\Filters\SelectFilter::make('type')
                    ->label('النوع')
                    ->options(AlertType::options()),
            ])
            ->actions([
                Tables\Actions\Action::make('approveTotp')
                    ->label('وافق على كود إضافي')
                    ->icon('heroicon-o-key')
                    ->color('primary')
                    ->visible(fn (Alert $r): bool => ! $r->resolved && $r->type === AlertType::TotpLimit)
                    ->requiresConfirmation()
                    ->modalDescription('سيُسمح للموظف بتوليد كود واحد إضافي لهذه المنصة في هذا التفعيل.')
                    ->action(function (Alert $record): void {
                        // Lock the alert row so two supervisors clicking
                        // "approve" at once can't both pass the resolved
                        // check and both grant an extra code.
                        $granted = DB::transaction(function () use ($record): bool {
                            $locked = Alert::query()->lockForUpdate()->find($record->id);
                            if ($locked === null || $locked->resolved) {
                                return false;
                            }

                            $assignmentId = $locked->payload['assignment_id'] ?? null;
                            $platform     = $locked->payload['platform'] ?? null;

                            $assignment = $assignmentId !== null
                                ? \App\Models\AccountAssignment::find($assignmentId)
                                : null;

                            if ($assignment !== null && in_array($platform, ['psn', 'ea'], true)) {
                                $assignment->increment($platform.'_totp_extra_allowed');
                            }

                            $locked->update([
                                'resolved'    => true,
                                'resolved_by' => auth()->id(),
                                'resolved_at' => now(),
                            ]);

                            return true;
                        });

                        if ($granted) {
                            // Notify the employee's live activation page —
                            // its button flips to enabled immediately, no poll.
                            $assignmentId = $record->payload['assignment_id'] ?? null;
                            $platform     = $record->payload['platform'] ?? null;
                            if ($assignmentId !== null && $platform !== null) {
                                \App\Events\TotpExtraAllowanceGranted::dispatch(
                                    (int) $record->user_id,
                                    (int) $assignmentId,
                                    (string) $platform,
                                );
                            }
                        }

                        if (! $granted) {
                            Notification::make()->warning()->title('تمت معالجة هذا الطلب مسبقاً')->send();
                            return;
                        }

                        Notification::make()->success()->title('تمت الموافقة — يقدر الموظف يولّد كوداً إضافياً الآن')->send();
                    }),

                Tables\Actions\Action::make('resolve')
                    ->label('حل')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Alert $r): bool => ! $r->resolved)
                    ->requiresConfirmation()
                    ->action(function (Alert $record): void {
                        $record->update([
                            'resolved'    => true,
                            'resolved_by' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('resolve_bulk')
                        ->label('حل المحدد')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each(fn (Alert $r) => $r->update([
                                'resolved'    => true,
                                'resolved_by' => auth()->id(),
                                'resolved_at' => now(),
                            ]));
                            Notification::make()->success()->title('تم حل '.$records->count())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlerts::route('/'),
        ];
    }
}
