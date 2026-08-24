@php
    $tenant = filament()->getTenant();
@endphp

@if ($tenant && $tenant->isFrozen())
    <div class="flex items-start gap-3 border-b-4 border-rose-600 bg-rose-100 px-4 py-3 dark:border-rose-500 dark:bg-rose-500/20">
        <x-heroicon-o-shield-exclamation class="h-6 w-6 flex-shrink-0 text-rose-700 dark:text-rose-300" />
        <div class="flex-1">
            <p class="text-sm font-bold text-rose-900 dark:text-rose-200">
                النظام في وضع تجميد الطوارئ منذ {{ $tenant->frozen_at->diffForHumans() }}
            </p>
            <p class="mt-0.5 text-sm text-rose-800 dark:text-rose-100">
                @if ($tenant->frozen_reason)
                    السبب: {{ $tenant->frozen_reason }}
                @endif
                @if ($tenant->freezer)
                    — جُمِّد بواسطة {{ $tenant->freezer->name }}
                @endif
            </p>
            <p class="mt-1 text-xs text-rose-700 dark:text-rose-200">
                كل عمليات كشف البيانات وتوليد الأكواد متوقفة تنتيًا. يتطلب تدخّل المالك لفكّ التجميد.
            </p>
        </div>
    </div>
@endif
