<x-filament-panels::page>
    @php
        $summary = $this->summary();
        $cards = [
            ['label' => 'الموظفون', 'value' => $summary['total'], 'class' => 'text-gray-950 dark:text-white'],
            ['label' => 'حققوا الهدف', 'value' => $summary['met'], 'class' => 'text-success-600 dark:text-success-400'],
            ['label' => 'يحققونه بعد المراجعة', 'value' => $summary['awaiting'], 'class' => 'text-info-600 dark:text-info-400'],
            ['label' => 'تحت الهدف', 'value' => $summary['behind'], 'class' => 'text-warning-600 dark:text-warning-400'],
            ['label' => 'لم يُنجزوا شيئاً', 'value' => $summary['idle'], 'class' => 'text-danger-600 dark:text-danger-400'],
        ];
    @endphp

    <div class="flex flex-wrap items-end gap-4 rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div>
            <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">اليوم</label>
            <div class="flex items-center gap-2">
                <x-filament::icon-button icon="heroicon-m-chevron-right" wire:click="previousDay" label="اليوم السابق" />
                <input
                    type="date"
                    wire:model.live="date"
                    max="{{ now(\App\Filament\App\Pages\DailyPerformance::timezone())->toDateString() }}"
                    class="block rounded-lg border-none bg-white py-1.5 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20"
                />
                <x-filament::icon-button icon="heroicon-m-chevron-left" wire:click="nextDay" label="اليوم التالي" :disabled="$this->isToday()" />
            </div>
        </div>

        <form wire:submit.prevent class="flex flex-1 flex-wrap items-end justify-end gap-4">
            <div class="max-w-xs flex-1">
                {{ $this->form }}
            </div>
            {{ $this->saveDefaultTargetAction }}
        </form>
    </div>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
        @foreach ($cards as $card)
            <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                <div class="mt-1 text-3xl font-bold {{ $card['class'] }}">{{ number_format($card['value']) }}</div>
            </div>
        @endforeach
    </div>

    @if ($summary['total'] > 0 && $summary['no_target'] === $summary['total'])
        <div class="rounded-2xl bg-warning-50 p-4 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400">
            لم يُحدَّد هدف يومي بعد — حدّد الهدف الافتراضي أعلاه (أو هدفاً خاصاً لكل موظف) لتظهر التحذيرات.
        </div>
    @endif

    {{ $this->table }}

    <p class="text-xs text-gray-500 dark:text-gray-400">
        يُحتسب التفعيل في يوم رفع الموظف للإثبات (لا يوم موافقة المشرف)، ويُعدّ مكتملاً فقط بعد الموافقة.
        عمود «أيام تحت الهدف» يشمل أيام العطل أيضاً، فهو مؤشر على النمط وليس حكماً نهائياً.
    </p>
</x-filament-panels::page>
