<x-filament-panels::page>
    @if (($pendingCount ?? 0) > 0)
        <div class="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-900">
            🔔 يوجد <strong>{{ $pendingCount }}</strong> طلب دخول بانتظار موافقتك.
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
