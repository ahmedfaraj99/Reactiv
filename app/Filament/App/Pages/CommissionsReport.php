<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Enums\UserRole;
use App\Models\AccountAssignment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-employee payout estimate: completed activations in a date range
 * times the tenant's rates — one flat price for activation-only accounts
 * and another for accounts with the play-N-matches add-on. The rate is currency-agnostic
 * and only the owner can set it — everyone else just reads the totals it
 * produces. A tenant that never set a rate still sees the completed
 * counts, just no money column, so this is useful from day one even
 * before payroll is wired up.
 */
class CommissionsReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'العمولات';

    protected static ?int $navigationSort = 14;

    protected static string $view = 'filament.app.pages.commissions-report';

    /** @var array<string,mixed> */
    public ?array $data = [];

    /** SQL: the assignment's account has the play-N-matches add-on. */
    private const IS_MATCH_ACCOUNT = 'EXISTS (SELECT 1 FROM accounts WHERE accounts.id = account_assignments.account_id AND accounts.matches_required > 0)';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

    public function getTitle(): string|Htmlable
    {
        return 'العمولات';
    }

    public function mount(): void
    {
        $tenant = filament()->getTenant();

        $this->form->fill([
            'commission_per_match_account' => $tenant->commission_per_match_account,
            'commission_per_activation'    => $tenant->commission_per_activation,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                TextInput::make('commission_per_match_account')
                    ->label('قيمة حساب بمباريات')
                    ->prefixIcon('heroicon-m-trophy')
                    ->numeric()
                    ->minValue(0)
                    ->disabled(fn (): bool => ! (auth()->user()?->isTenantOwner() ?? false)),

                TextInput::make('commission_per_activation')
                    ->label('قيمة حساب تفعيل فقط')
                    ->prefixIcon('heroicon-m-check-badge')
                    ->numeric()
                    ->minValue(0)
                    ->disabled(fn (): bool => ! (auth()->user()?->isTenantOwner() ?? false)),

                Placeholder::make('rates_hint')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content('اترك الحقلين فارغين لعرض الأعداد فقط بدون مبالغ. الحقل الفارغ يُحسب صفراً.'),
            ])
            ->statePath('data');
    }

    public function saveRateAction(): Action
    {
        return Action::make('saveRate')
            ->label('حفظ')
            ->visible(fn (): bool => auth()->user()?->isTenantOwner() ?? false)
            ->action(function (): void {
                $state = $this->form->getState();
                $clean = fn (mixed $rate): mixed => $rate !== null && $rate !== '' ? $rate : null;

                filament()->getTenant()->update([
                    'commission_per_match_account' => $clean($state['commission_per_match_account'] ?? null),
                    'commission_per_activation'    => $clean($state['commission_per_activation'] ?? null),
                ]);

                Notification::make()->success()->title('تم حفظ الأسعار')->send();
            });
    }

    public function table(Table $table): Table
    {
        $tenant = filament()->getTenant();

        return $table
            ->query($this->commissionsQuery())
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')
                    ->label('الموظف')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('matches_count')
                    ->label('بمباريات')
                    ->badge()
                    ->color('purple')
                    ->icon('heroicon-m-trophy')
                    ->sortable(),

                Tables\Columns\TextColumn::make('activation_only_count')
                    ->label('تفعيل فقط')
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-m-check-badge')
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_count')
                    ->label('الإجمالي')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('المستحق')
                    ->weight('bold')
                    ->visible($tenant->hasCommissionRates())
                    ->state(fn (AccountAssignment $record): string => number_format(
                        $tenant->commissionFor((int) $record->activation_only_count, (int) $record->matches_count) ?? 0,
                        2,
                    )),
            ])
            ->filters([
                Tables\Filters\Filter::make('completed_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('من')
                            ->default(now()->startOfMonth()),
                        \Filament\Forms\Components\DatePicker::make('to')
                            ->label('إلى')
                            ->default(now()->endOfMonth()),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('completed_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('completed_at', '<=', $d)))
                    ->indicateUsing(fn (array $data): ?string => filled($data['from'] ?? null) || filled($data['to'] ?? null)
                        ? 'من '.($data['from'] ?? '…').' إلى '.($data['to'] ?? '…')
                        : null),
            ])
            ->defaultSort('completed_count', 'desc')
            ->emptyStateHeading('لا يوجد تفعيلات مكتملة في هذه الفترة');
    }

    private function commissionsQuery(): Builder
    {
        $tenantId = filament()->getTenant()?->id;
        $u = auth()->user();

        $query = AccountAssignment::query()
            ->with('employee')
            ->selectRaw('employee_id')
            ->selectRaw('MIN(id) as id')
            ->selectRaw('COUNT(*) as completed_count')
            // Split by the account's kind since each kind has its own rate.
            ->selectRaw('COUNT(*) FILTER (WHERE '.self::IS_MATCH_ACCOUNT.') as matches_count')
            ->selectRaw('COUNT(*) FILTER (WHERE NOT '.self::IS_MATCH_ACCOUNT.') as activation_only_count')
            ->where('status', AccountAssignment::STATUS_COMPLETED);

        if ($tenantId === null || $u === null) {
            return $query->whereRaw('1 = 0')->groupBy('employee_id');
        }

        $query->where('tenant_id', $tenantId);

        $inScopeEmployeeIds = $this->inScopeEmployeeIds($u);
        if ($inScopeEmployeeIds !== null) {
            $query->whereIn('employee_id', $inScopeEmployeeIds);
        }

        return $query->groupBy('employee_id');
    }

    /**
     * Same reach rule as OverviewStatsWidget/leaderboard: owner sees every
     * employee (null = no filter), manager/supervisor only their reach.
     *
     * @return array<int,int>|null
     */
    private function inScopeEmployeeIds(User $u): ?array
    {
        if ($u->isTenantOwner()) {
            return null;
        }

        $q = User::query()->whereHas('roles', fn ($r) => $r->where('name', UserRole::Employee->value));

        if ($u->isManager()) {
            return $q->whereIn('office_id', $u->managedOffices()->pluck('id'))->pluck('id')->all();
        }

        if ($u->isSupervisor()) {
            return $q->where('office_id', $u->office_id)->pluck('id')->all();
        }

        return [];
    }
}
