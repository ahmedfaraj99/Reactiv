<x-filament-panels::page>
    {{-- One card per kind so the manager never reads a mixed total. The
         ring marks the kind currently picked on the form below. --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div @class([
            'rounded-2xl bg-white p-6 ring-1 dark:bg-gray-900',
            'ring-2 ring-purple-500' => $this->selectedMatchesType() === 'matches',
            'ring-gray-950/5 dark:ring-white/10' => $this->selectedMatchesType() !== 'matches',
        ])>
            <p class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                <x-heroicon-m-trophy class="h-4 w-4 text-purple-500" /> حسابات بمباريات متاحة
            </p>
            <p class="mt-1 font-mono text-3xl font-bold text-purple-600 dark:text-purple-400">{{ $this->availableCount('matches') }}</p>
        </div>
        <div @class([
            'rounded-2xl bg-white p-6 ring-1 dark:bg-gray-900',
            'ring-2 ring-emerald-500' => $this->selectedMatchesType() === 'activation',
            'ring-gray-950/5 dark:ring-white/10' => $this->selectedMatchesType() !== 'activation',
        ])>
            <p class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                <x-heroicon-m-check-badge class="h-4 w-4 text-emerald-500" /> حسابات تفعيل فقط متاحة
            </p>
            <p class="mt-1 font-mono text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ $this->availableCount('activation') }}</p>
        </div>
    </div>

    <form wire:submit.prevent>
        <div class="rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            {{ $this->form }}
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            {{ $this->distributeAction }}
            {{ $this->equalizeAction }}
        </div>
    </form>

    {{-- Who can't take the picked client, why, and a one-click way to
         free them: unopened and abandoned accounts go back to the stock. --}}
    @php($blocked = $this->blockedEmployees())
    @if (count($blocked) > 0)
        <div class="mt-8 rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        موظفون لا يمكنهم استلام هذا العميل الآن ({{ count($blocked) }})
                    </h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        «تحرير» يرجع للمخزون الحسابات غير المفتوحة والعالقة (مفتوحة منذ أكثر من {{ \App\Filament\App\Resources\AccountResource::STUCK_AFTER_HOURS }} ساعة ولم تكتمل).
                    </p>
                </div>
                {{ $this->releaseAllAction }}
            </div>

            <ul class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach ($blocked as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $row['status'] }}</p>
                        </div>
                        @if ($row['can_release'])
                            <div class="flex items-center gap-3">
                                <span class="text-sm text-amber-600 dark:text-amber-400">قابل للتحرير: {{ $row['releasable'] }}</span>
                                {{ ($this->releaseEmployeeAction)(['employee' => $row['id']]) }}
                            </div>
                        @else
                            <span class="text-sm text-gray-500 dark:text-gray-400">يعمل عليها الآن — انتظر حتى ينهيها</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-filament-panels::page>
