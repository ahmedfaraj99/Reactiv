<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\AccountResource;
use App\Models\Account;
use App\Models\RevealLog;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Security review tool: pick an employee, see every account they've
 * actually revealed credentials for (not just been assigned) — grouped,
 * with first/last reveal time. The point is offboarding/incident
 * response: if this employee leaves or is suspected of misconduct, this
 * is exactly the list of accounts whose passwords need rotating on the
 * PSN/EA side, no guessing.
 */
class EmployeeExposureReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static ?string $navigationLabel = 'تقرير كشف الموظف';

    protected static ?int $navigationSort = 13;

    protected static string $view = 'filament.app.pages.employee-exposure-report';

    public ?int $employeeId = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && ($u->isTenantOwnerOrManager() || $u->isSupervisor());
    }

    public function getTitle(): string|Htmlable
    {
        return 'تقرير كشف الموظف';
    }

    /** @return array<int,string> */
    public function employeeOptions(): array
    {
        return AccountResource::employeeOptions();
    }

    public function updatedEmployeeId(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                // $employeeId is a public Livewire property — never trust
                // it blindly. Only allow IDs the current user is actually
                // scoped to see (same tenant, same office reach), or a
                // malicious client could set it to any user's ID and read
                // another tenant's reveal history.
                if ($this->employeeId === null || ! array_key_exists($this->employeeId, $this->employeeOptions())) {
                    return RevealLog::query()->whereRaw('1 = 0');
                }

                return RevealLog::query()
                    ->with('account')
                    ->select('account_id')
                    ->selectRaw('MIN(id) as id')
                    ->selectRaw('MIN(created_at) as first_revealed_at')
                    ->selectRaw('MAX(created_at) as last_revealed_at')
                    ->selectRaw('COUNT(*) as reveal_count')
                    ->where('action', 'reveal_credentials')
                    ->where('user_id', $this->employeeId)
                    ->groupBy('account_id');
            })
            ->columns([
                Tables\Columns\TextColumn::make('account_id')
                    ->label('حساب رقم')
                    ->prefix('#')
                    ->fontFamily('mono')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('account.email')
                    ->label('البريد')
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? AccountResource::maskEmail($state) : '—'),

                Tables\Columns\TextColumn::make('account.status')
                    ->label('حالة الحساب الآن')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'available' => 'success',
                        'assigned'  => 'warning',
                        'activated' => 'info',
                        'retired'   => 'gray',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'available' => 'متاح',
                        'assigned'  => 'مخصَّص',
                        'activated' => 'مفعَّل',
                        'retired'   => 'مؤرشف',
                        default     => '—',
                    }),

                Tables\Columns\TextColumn::make('first_revealed_at')
                    ->label('أول كشف')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_revealed_at')
                    ->label('آخر كشف')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reveal_count')
                    ->label('عدد مرات الكشف')
                    ->badge()
                    ->color(fn (int $state): string => $state > 1 ? 'danger' : 'gray'),
            ])
            ->defaultSort('last_revealed_at', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading(fn (): string => $this->employeeId === null
                ? 'اختر موظفاً أعلاه لعرض تقريره'
                : 'هذا الموظف لم يكشف بيانات أي حساب بعد')
            ->emptyStateIcon('heroicon-o-eye');
    }
}
