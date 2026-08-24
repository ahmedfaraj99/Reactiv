@php $tenant = $this->getTenant(); @endphp

<div class="rounded-2xl {{ $tenant->isFrozen() ? 'bg-rose-50 ring-1 ring-rose-300 dark:bg-rose-500/10 dark:ring-rose-500/40' : 'bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' }} p-4">
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            @if ($tenant->isFrozen())
                <x-heroicon-o-shield-exclamation class="h-6 w-6 text-rose-600 dark:text-rose-400" />
                <div>
                    <p class="text-sm font-bold text-rose-900 dark:text-rose-200">النظام مجمّد الآن</p>
                    <p class="text-xs text-rose-700 dark:text-rose-300">
                        منذ {{ $tenant->frozen_at->diffForHumans() }}
                        @if ($tenant->freezer) — بواسطة {{ $tenant->freezer->name }} @endif
                    </p>
                </div>
            @else
                <x-heroicon-o-shield-check class="h-6 w-6 text-emerald-600 dark:text-emerald-400" />
                <div>
                    <p class="text-sm font-bold text-gray-950 dark:text-white">النظام يعمل بشكل طبيعي</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        زر التجميد يوقف كل عمليات كشف البيانات وتوليد الأكواد فوراً.
                    </p>
                </div>
            @endif
        </div>

        <div>
            {{ $this->freezeAction }}
            {{ $this->unfreezeAction }}
        </div>
    </div>

    <x-filament-actions::modals />
</div>
