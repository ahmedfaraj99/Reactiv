@php
    $counts = $this->getMatchesTypeCounts();
    $current = $this->matchesType;
    $options = [
        'all'        => ['label' => 'الكل',        'icon' => 'heroicon-m-squares-2x2', 'active' => 'bg-gray-800 text-white ring-gray-800 dark:bg-white dark:text-gray-900 dark:ring-white'],
        'matches'    => ['label' => 'بمباريات',    'icon' => 'heroicon-m-trophy',      'active' => 'bg-purple-600 text-white ring-purple-600'],
        'activation' => ['label' => 'تفعيل فقط',   'icon' => 'heroicon-m-check-badge', 'active' => 'bg-emerald-600 text-white ring-emerald-600'],
    ];
@endphp

<div class="grid grid-cols-3 gap-2" role="tablist" aria-label="نوع الحساب">
    @foreach ($options as $key => $opt)
        <button type="button"
                role="tab"
                aria-selected="{{ $current === $key ? 'true' : 'false' }}"
                wire:click="$set('matchesType', '{{ $key }}')"
                @class([
                    'flex min-h-11 items-center justify-center gap-1.5 rounded-xl px-2 py-2 text-sm font-bold ring-1 transition',
                    $opt['active'] => $current === $key,
                    'bg-white text-gray-600 ring-gray-950/10 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5' => $current !== $key,
                ])>
            <x-filament::icon :icon="$opt['icon']" class="h-5 w-5 flex-shrink-0" />
            <span class="truncate">{{ $opt['label'] }}</span>
            <span @class([
                'rounded-md px-1.5 text-xs',
                'bg-white/25' => $current === $key,
                'bg-gray-100 dark:bg-white/10' => $current !== $key,
            ])>{{ $counts[$key] }}</span>
        </button>
    @endforeach
</div>
