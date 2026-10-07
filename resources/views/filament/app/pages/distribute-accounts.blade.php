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
</x-filament-panels::page>
