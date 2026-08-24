<x-filament-panels::page>
    @php
        $locked = $this->getLockedAssignment();
        $stats = $this->todayStats();
    @endphp

    {{-- Today snapshot for the employee. Hidden when there's nothing yet
         to celebrate/reflect on — an empty "0 activations today" is
         demoralising to see the moment you open the app in the morning. --}}
    @if ($stats['completed_count'] > 0)
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs text-gray-500 dark:text-gray-400">اليوم</p>
                <p class="mt-1 text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $stats['completed_count'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">تفعيل مكتمل</p>
            </div>

            @if ($stats['avg_minutes'] !== null)
                <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs text-gray-500 dark:text-gray-400">متوسط وقتك</p>
                    <p class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">
                        {{ $stats['avg_minutes'] }}<span class="text-sm font-normal text-gray-500 dark:text-gray-400"> د</span>
                    </p>
                    @if ($stats['comparison_percent'] !== null && $stats['comparison_percent'] !== 0)
                        <p class="text-xs {{ $stats['comparison_percent'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                            @if ($stats['comparison_percent'] > 0)
                                أسرع من الفريق بـ {{ $stats['comparison_percent'] }}%
                            @else
                                أبطأ من الفريق بـ {{ abs($stats['comparison_percent']) }}%
                            @endif
                        </p>
                    @elseif ($stats['team_avg_minutes'] !== null)
                        <p class="text-xs text-gray-500 dark:text-gray-400">متوسط الفريق: {{ $stats['team_avg_minutes'] }} د</p>
                    @endif
                </div>
            @endif

            @if ($stats['currency_configured'])
                <div class="col-span-2 rounded-2xl bg-primary-50 p-4 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:ring-primary-500/30 sm:col-span-2">
                    <p class="text-xs text-primary-700 dark:text-primary-300">مستحقك اليوم</p>
                    <p class="mt-1 font-mono text-2xl font-bold text-primary-900 dark:text-primary-100">{{ $stats['earnings'] }}</p>
                    <p class="text-xs text-primary-700/70 dark:text-primary-300/70">{{ $stats['completed_count'] }} × قيمة التفعيل</p>
                </div>
            @endif
        </div>
    @endif

    @if ($locked)
        <div class="mb-4 flex items-center gap-3 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
            <x-heroicon-o-lock-closed class="h-6 w-6 flex-shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="flex-1">
                <p class="text-sm font-bold text-amber-900 dark:text-amber-300">أنت مقفل على حساب #{{ $locked->account_id }} حالياً</p>
                <p class="mt-0.5 text-sm text-amber-800 dark:text-amber-200">أكمله بإرسال إثبات الإنجاز أو تسجيل بيانات خطأ قبل فتح أي حساب آخر.</p>
            </div>
            <a href="{{ route('filament.app.pages.activation', ['tenant' => filament()->getTenant()->slug, 'assignment' => $locked->id]) }}"
               class="flex-shrink-0 rounded-xl bg-amber-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-amber-700">
                افتحه الآن
            </a>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
