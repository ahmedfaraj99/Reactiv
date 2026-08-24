<x-filament-panels::page>
    <form wire:submit.prevent>
        <div class="mb-6 flex flex-wrap items-end gap-4 rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="max-w-xs flex-1">
                {{ $this->form }}
            </div>
            {{ $this->saveRateAction }}
        </div>
    </form>

    {{ $this->table }}
</x-filament-panels::page>
