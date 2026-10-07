{{-- Built from Filament's own tabs component so it matches the status
     tabs on the accounts list and is styled by Filament's stylesheet,
     not by our compiled theme. --}}
@php
    $counts = $this->getMatchesTypeCounts();
    $options = [
        'all'        => ['label' => 'كل الأنواع',          'icon' => 'heroicon-m-squares-2x2', 'color' => 'gray'],
        'matches'    => ['label' => 'حسابات بمباريات',     'icon' => 'heroicon-m-trophy',      'color' => 'purple'],
        'activation' => ['label' => 'تفعيل فقط (بدون مباريات)', 'icon' => 'heroicon-m-check-badge', 'color' => 'success'],
    ];
@endphp

<x-filament::tabs label="نوع الحساب">
    @foreach ($options as $key => $opt)
        <x-filament::tabs.item
            :active="$this->matchesType === $key"
            :icon="$opt['icon']"
            :icon-color="$opt['color']"
            :badge="$counts[$key]"
            :badge-color="$opt['color']"
            wire:click="$set('matchesType', '{{ $key }}')"
        >
            {{ $opt['label'] }}
        </x-filament::tabs.item>
    @endforeach
</x-filament::tabs>
