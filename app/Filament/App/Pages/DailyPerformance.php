<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\AccountAssignment;
use App\Models\RevealLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
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
use Illuminate\Support\Collection;

/**
 * Daily quota tracking: every employee has a number of activations they
 * must finish per day (their own override, or the tenant default). This
 * page lists each employee in the viewer's reach against that number for
 * a chosen day — worst performers first — plus how many of the previous
 * seven days they also fell short, so a supervisor/manager can tell a
 * one-off bad day from a pattern before deciding to let someone go.
 *
 * Reach follows User::visibleEmployeeIds(): owner = whole tenant,
 * manager = offices they run, supervisor = their own office. Only the
 * owner and managers can change targets — a supervisor is themselves
 * judged on their office's output, so letting them lower the bar for
 * their own team would defeat the point.
 */
class DailyPerformance extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    /** How many days before the selected one feed the "repeat offender" column. */
    public const HISTORY_DAYS = 7;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'الأداء اليومي';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.app.pages.daily-performance';

    /** Day being reviewed, Y-m-d. Public Livewire state — always re-validated. */
    public string $date = '';

    /** @var array<string,mixed> */
    public ?array $data = [];

    /** @var array<int,int>|null memoized per request */
    private ?array $missesCache = null;

    /** @var Collection<int,User>|null memoized per request */
    private ?Collection $rowsCache = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

    /** Timezone whose midnight starts a new working day (see config/app.php). */
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', config('app.timezone'));
    }

    public static function canEditTargets(): bool
    {
        return auth()->user()?->isTenantOwnerOrManager() ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'متابعة الأداء اليومي';
    }

    public function mount(): void
    {
        $this->date = CarbonImmutable::now(self::timezone())->toDateString();

        $this->form->fill([
            'default_daily_target' => filament()->getTenant()->default_daily_target,
        ]);
    }

    public function updatedDate(): void
    {
        $this->day(); // normalizes $this->date if the client sent garbage
        $this->missesCache = null;
        $this->rowsCache = null;
        $this->resetTable();
    }

    public function previousDay(): void
    {
        $this->date = $this->day()->subDay()->toDateString();
        $this->updatedDate();
    }

    public function nextDay(): void
    {
        $this->date = $this->day()->addDay()->toDateString();
        $this->updatedDate();
    }

    /**
     * The selected day as local midnight in the business timezone,
     * clamped to [anything past, today]. Future days have nothing to show
     * and would make every employee look idle.
     */
    public function day(): CarbonImmutable
    {
        $today = CarbonImmutable::today(self::timezone());

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $this->date, self::timezone());
        } catch (\Throwable) {
            $day = false;
        }

        if ($day === false || $day->gt($today)) {
            $day = $today;
        }

        $this->date = $day->toDateString();

        return $day;
    }

    public function isToday(): bool
    {
        return $this->day()->equalTo(CarbonImmutable::today(self::timezone()));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('default_daily_target')
                    ->label('الهدف اليومي الافتراضي (تفعيلات)')
                    ->helperText('يُطبَّق على كل موظف ليس له هدف خاص. اتركه فارغاً لإلغاء الهدف.')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1000)
                    ->disabled(fn (): bool => ! self::canEditTargets()),
            ])
            ->statePath('data');
    }

    public function saveDefaultTargetAction(): Action
    {
        return Action::make('saveDefaultTarget')
            ->label('حفظ')
            ->visible(fn (): bool => self::canEditTargets())
            ->action(function (): void {
                $target = $this->form->getState()['default_daily_target'] ?? null;

                filament()->getTenant()->update([
                    'default_daily_target' => filled($target) ? (int) $target : null,
                ]);

                $this->missesCache = null;
                $this->rowsCache = null;

                Notification::make()->success()->title('تم حفظ الهدف الافتراضي')->send();
            });
    }

    // ── Data ─────────────────────────────────────────────────────────

    /**
     * One row per in-scope, active employee with the day's counters
     * attached. Wrapped in a sub-select so the computed columns
     * (done_count, progress, …) can be sorted on like real columns.
     */
    public function performanceQuery(): Builder
    {
        $u = auth()->user();
        $tenant = filament()->getTenant();

        if ($u === null || $tenant === null) {
            return User::query()->whereRaw('1 = 0');
        }

        // Local day boundaries, converted to UTC to match stored timestamps.
        $from = $this->day()->utc();
        $to = $this->day()->addDay()->utc();
        $default = $tenant->default_daily_target;

        $countFor = fn (\Closure $scope) => AccountAssignment::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('account_assignments.employee_id', 'users.id')
            ->where('account_assignments.tenant_id', $tenant->id)
            ->tap($scope);

        $inner = User::query()
            ->select('users.*')
            ->where('users.tenant_id', $tenant->id)
            ->where('users.active', true)
            ->whereIn('users.id', $u->visibleEmployeeIds())
            // Integer-cast before interpolation — nothing user-typed ever
            // reaches this string.
            ->selectRaw('COALESCE(users.daily_target, '.($default !== null ? (int) $default : 'NULL').') as effective_target')
            ->addSelect([
                'done_count' => $countFor(fn ($q) => $q->doneBetween($from, $to)),
                'awaiting_count' => $countFor(fn ($q) => $q
                    ->where('account_assignments.status', AccountAssignment::STATUS_AWAITING_REVIEW)
                    ->where('account_assignments.submitted_at', '>=', $from)
                    ->where('account_assignments.submitted_at', '<', $to)),
                'failed_count' => $countFor(fn ($q) => $q
                    ->where('account_assignments.status', AccountAssignment::STATUS_FAILED)
                    ->where('account_assignments.completed_at', '>=', $from)
                    ->where('account_assignments.completed_at', '<', $to)),
                'last_activity_at' => RevealLog::query()
                    ->selectRaw('MAX(created_at)')
                    ->whereColumn('reveal_logs.user_id', 'users.id'),
            ]);

        return User::query()
            ->fromSub($inner->toBase(), 'users')
            ->select('users.*')
            // Share of the quota met; NULL for employees without a
            // target so they sink below everyone who has one.
            ->selectRaw('CASE WHEN users.effective_target > 0 '
                .'THEN users.done_count::float / users.effective_target END as progress')
            ->with('office');
    }

    /** @return Collection<int,User> */
    public function rows(): Collection
    {
        return $this->rowsCache ??= $this->performanceQuery()->get();
    }

    /**
     * Per employee: how many of the HISTORY_DAYS days before the selected
     * one ended below target. One grouped query for the whole scope
     * instead of one per row. Days with zero activations never appear in
     * the GROUP BY, so they're counted as misses by default — which also
     * means days off count; the column is a pattern hint, not a verdict.
     *
     * @return array<int,int> employee id => missed days
     */
    public function missedDays(): array
    {
        if ($this->missesCache !== null) {
            return $this->missesCache;
        }

        $rows = $this->rows()->filter(fn (User $r): bool => (int) $r->effective_target > 0);
        if ($rows->isEmpty()) {
            return $this->missesCache = [];
        }

        $localTo = $this->day();
        $to = $localTo->utc();
        $from = $localTo->subDays(self::HISTORY_DAYS)->utc();

        $perDay = AccountAssignment::query()
            ->selectRaw('employee_id')
            // Stored UTC → local calendar day.
            ->selectRaw("DATE((COALESCE(submitted_at, completed_at) AT TIME ZONE 'UTC') AT TIME ZONE ?) as day", [self::timezone()])
            ->selectRaw('COUNT(*) as n')
            ->where('tenant_id', filament()->getTenant()->id)
            ->whereIn('employee_id', $rows->pluck('id'))
            ->doneBetween($from, $to)
            ->groupBy('employee_id', 'day')
            ->get()
            ->groupBy('employee_id');

        $misses = [];
        foreach ($rows as $row) {
            $target = (int) $row->effective_target;
            $days = $perDay->get($row->id, collect());
            $metDays = $days->filter(fn ($d): bool => (int) $d->n >= $target)->count();

            // Don't count days before the employee existed.
            $joined = $row->created_at?->copy()->setTimezone(self::timezone())->startOfDay();
            $window = min(self::HISTORY_DAYS, max(0, (int) $joined?->diffInDays($localTo)));

            $misses[$row->id] = max(0, $window - $metDays);
        }

        return $this->missesCache = $misses;
    }

    /**
     * Where an employee stands for the day. `awaiting` = hit the target
     * only if the supervisor approves what's still in review.
     */
    public static function statusFor(User $row): string
    {
        $target = (int) $row->effective_target;
        $done = (int) $row->done_count;
        $awaiting = (int) $row->awaiting_count;

        return match (true) {
            $target <= 0                  => 'no_target',
            $done >= $target              => 'met',
            $done + $awaiting >= $target  => 'awaiting',
            $done + $awaiting === 0       => 'idle',
            default                       => 'behind',
        };
    }

    /** @return array{total:int, met:int, awaiting:int, behind:int, idle:int, no_target:int} */
    public function summary(): array
    {
        $counts = ['total' => 0, 'met' => 0, 'awaiting' => 0, 'behind' => 0, 'idle' => 0, 'no_target' => 0];

        foreach ($this->rows() as $row) {
            $counts['total']++;
            $counts[self::statusFor($row)]++;
        }

        return $counts;
    }

    // ── Table ────────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->performanceQuery())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الموظف')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('office.name')
                    ->label('المكتب')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('effective_target')
                    ->label('الهدف')
                    ->placeholder('بدون')
                    ->description(fn (User $record): ?string => $record->daily_target !== null ? 'هدف خاص' : null),

                Tables\Columns\TextColumn::make('done_count')
                    ->label('أنجز')
                    ->badge()
                    ->color(fn (User $record): string => match (self::statusFor($record)) {
                        'met'       => 'success',
                        'awaiting'  => 'info',
                        'behind'    => 'warning',
                        'idle'      => 'danger',
                        default     => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('progress')
                    ->label('نسبة الإنجاز')
                    ->state(fn (User $record): ?string => $record->progress !== null
                        ? number_format(min(999, (float) $record->progress * 100), 0).'%'
                        : null)
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('awaiting_count')
                    ->label('بانتظار المراجعة')
                    ->color(fn ($state): string => (int) $state > 0 ? 'info' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('failed_count')
                    ->label('فاشلة')
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->state(fn (User $record): string => self::statusFor($record))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'met'       => 'حقق الهدف',
                        'awaiting'  => 'يحققه بعد المراجعة',
                        'behind'    => 'تحت الهدف',
                        'idle'      => 'لم يُنجز شيئاً',
                        default     => 'بدون هدف',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'met'       => 'success',
                        'awaiting'  => 'info',
                        'behind'    => 'warning',
                        'idle'      => 'danger',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('missed_days')
                    ->label('أيام تحت الهدف (آخر '.self::HISTORY_DAYS.')')
                    ->state(fn (User $record): ?int => $this->missedDays()[$record->id] ?? null)
                    ->formatStateUsing(fn ($state): string => ((int) $state).' / '.self::HISTORY_DAYS)
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null                                      => 'gray',
                        $state >= (int) ceil(self::HISTORY_DAYS / 2)         => 'danger',
                        $state > 0                                           => 'warning',
                        default                                              => 'success',
                    }),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('آخر نشاط')
                    ->since()
                    ->placeholder('لم يعمل بعد')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('setTarget')
                    ->label('تعديل الهدف')
                    ->icon('heroicon-m-adjustments-horizontal')
                    ->visible(fn (): bool => self::canEditTargets())
                    ->fillForm(fn (User $record): array => ['daily_target' => $record->daily_target])
                    ->form([
                        TextInput::make('daily_target')
                            ->label('هدف خاص لهذا الموظف')
                            ->helperText('اتركه فارغاً ليتبع الهدف الافتراضي.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1000),
                    ])
                    ->action(function (User $record, array $data): void {
                        // $record came out of performanceQuery(), which is
                        // already restricted to the viewer's reach — a
                        // forged record key outside it never resolves.
                        User::query()->whereKey($record->id)->update([
                            'daily_target' => filled($data['daily_target'] ?? null) ? (int) $data['daily_target'] : null,
                        ]);

                        $this->missesCache = null;
                        $this->rowsCache = null;

                        Notification::make()->success()->title('تم تحديث هدف '.$record->name)->send();
                    }),
            ])
            // Worst first: lowest share of target on top, no-target rows last.
            ->defaultSort('progress', 'asc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('لا يوجد موظفون نشطون ضمن نطاقك')
            ->emptyStateIcon('heroicon-o-users');
    }
}
