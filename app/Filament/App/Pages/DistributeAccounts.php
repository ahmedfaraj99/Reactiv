<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\AccountResource;
use App\Models\Account;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
 * thousands of accounts at once: pick every employee you want to give
 * accounts to in one searchable multi-select, then just fill in a count
 * next to each name. No adding rows one by one. The system claims that
 * many available accounts (oldest first) for each employee in one submit.
 * Counts don't have to be equal.
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
        $this->form->fill();
    }

    public function form(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        return $form
            ->schema([
                Select::make('employee_ids')
                    ->label('اختر الموظفين')
                    ->helperText('اختر كل من تريد توزيع حسابات عليه دفعة واحدة — لا حاجة لإضافتهم واحداً تلو الآخر.')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->options(fn () => AccountResource::employeeOptions())
                    ->live()
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

    public function availableCount(): int
    {
        return Account::query()
            ->where('tenant_id', filament()->getTenant()->id)
            ->where('manager_id', self::scopedManagerId())
            ->where('status', 'available')
            ->count();
    }

    public function equalizeAction(): Action
    {
        return Action::make('equalize')
            ->label('وزّع بالتساوي على المُختارين')
            ->color('gray')
            ->action(function (): void {
                $rows = $this->form->getState()['rows'] ?? [];
                $employeeCount = count($rows);

                if ($employeeCount === 0) {
                    Notification::make()->warning()->title('اختر موظفاً واحداً على الأقل أولاً')->send();
                    return;
                }

                $available = $this->availableCount();
                $share = intdiv($available, $employeeCount);
                $remainder = $available % $employeeCount;

                foreach ($rows as $i => $row) {
                    $rows[$i]['count'] = $share + ($i < $remainder ? 1 : 0);
                }

                $this->form->fill(['employee_ids' => array_column($rows, 'employee_id'), 'rows' => $rows]);
            });
    }

    public function distributeAction(): Action
    {
        return Action::make('distribute')
            ->label('توزيع الآن')
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription('سيُخصَّص العدد المحدد من الحسابات المتاحة (الأقدم رفعاً أولاً) لكل موظف مباشرة.')
            ->action(function (): void {
                $state = $this->form->getState();
                $rows = $state['rows'] ?? [];

                if (empty($rows)) {
                    Notification::make()->warning()->title('اختر موظفاً واحداً على الأقل')->send();
                    return;
                }

                $totalRequested = array_sum(array_column($rows, 'count'));
                $available = $this->availableCount();

                if ($totalRequested > $available) {
                    Notification::make()
                        ->danger()
                        ->title('العدد المطلوب أكبر من المتاح')
                        ->body("طلبت {$totalRequested}، والمتاح فعلياً {$available} فقط.")
                        ->send();
                    return;
                }

                $tenantId = filament()->getTenant()->id;
                $managerId = self::scopedManagerId();
                $summary = [];

                DB::transaction(function () use ($rows, $tenantId, $managerId, &$summary): void {
                    foreach ($rows as $row) {
                        $employeeId = (int) $row['employee_id'];
                        $count = (int) $row['count'];

                        $accounts = Account::query()
                            ->where('tenant_id', $tenantId)
                            ->where('manager_id', $managerId)
                            ->where('status', 'available')
                            ->orderBy('created_at')
                            ->lockForUpdate()
                            ->limit($count)
                            ->get();

                        AccountResource::assignAccounts($accounts, $employeeId);

                        $employee = User::find($employeeId);
                        $summary[] = ($employee?->name ?? '#'.$employeeId).': '.$accounts->count();
                    }
                });

                Notification::make()
                    ->success()
                    ->title('اكتمل التوزيع')
                    ->body(implode(' — ', $summary))
                    ->send();

                $this->form->fill();
            });
    }
}
