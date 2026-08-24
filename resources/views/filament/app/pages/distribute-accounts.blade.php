<x-filament-panels::page>
    <div class="mb-6 rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="text-xs text-gray-500 dark:text-gray-400">الحسابات المتاحة الآن</p>
        <p class="mt-1 font-mono text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $this->availableCount() }}</p>
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
