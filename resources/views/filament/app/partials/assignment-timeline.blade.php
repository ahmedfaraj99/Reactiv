@php
    // Ordered list of every state change on this assignment. Each entry
    // has an absolute timestamp, a label, and (once rendered) the delta
    // from the previous timestamp. Nulls are dropped — the timeline
    // only shows what actually happened, so an assignment stuck at
    // "reveal_credentials" and never advanced to TOTP won't fake a
    // TOTP row.
    $rows = [
        ['label' => 'مُخصَّص للموظف', 'time' => $assignment->assigned_at,           'icon' => 'heroicon-o-user-plus',           'color' => 'gray'],
        ['label' => 'بدأ التفعيل',       'time' => $assignment->started_at,            'icon' => 'heroicon-o-play',                'color' => 'info'],
        ['label' => 'كُشفت البيانات',    'time' => $assignment->credentials_revealed_at,'icon' => 'heroicon-o-eye',                'color' => 'warning'],
        ['label' => 'وُلِّد كود TOTP',    'time' => $assignment->first_totp_at,         'icon' => 'heroicon-o-shield-check',        'color' => 'warning'],
        ['label' => 'أُرسل الإثبات',     'time' => $assignment->submitted_at,          'icon' => 'heroicon-o-paper-airplane',     'color' => 'primary'],
        ['label' => 'راجعه المشرف',      'time' => $assignment->reviewed_at,           'icon' => 'heroicon-o-clipboard-document-check', 'color' => 'primary'],
        ['label' => 'اكتمل',             'time' => $assignment->completed_at,          'icon' => 'heroicon-o-check-circle',        'color' => 'success'],
    ];

    $rows = array_values(array_filter($rows, fn (array $r): bool => $r['time'] !== null));

    $totalSeconds = null;
    if (! empty($rows)) {
        $totalSeconds = (int) $rows[0]['time']->diffInSeconds(end($rows)['time']);
    }
@endphp

<div class="space-y-4">
    @if (empty($rows))
        <p class="text-sm text-gray-500 dark:text-gray-400">
            لا توجد بيانات توقيت لهذا التفعيل.
        </p>
    @else
        <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-300">
            <span class="font-semibold">الموظف:</span> {{ $assignment->employee?->name ?? '—' }}
            @if ($totalSeconds !== null && $totalSeconds > 0)
                <span class="mx-2 text-gray-400">•</span>
                <span class="font-semibold">إجمالي المدة:</span>
                @if ($totalSeconds >= 60)
                    {{ (int) round($totalSeconds / 60) }} دقيقة
                @else
                    {{ $totalSeconds }} ثانية
                @endif
            @endif
        </div>

        <ol class="relative space-y-4 border-s-2 border-gray-200 ps-6 dark:border-white/10">
            @foreach ($rows as $i => $row)
                @php
                    $delta = null;
                    if ($i > 0) {
                        $seconds = (int) $rows[$i - 1]['time']->diffInSeconds($row['time']);
                        if ($seconds < 60) {
                            $delta = 'بعد ' . $seconds . ' ثانية';
                        } elseif ($seconds < 3600) {
                            $delta = 'بعد ' . (int) round($seconds / 60) . ' دقيقة';
                        } else {
                            $delta = 'بعد ' . (int) round($seconds / 3600, 1) . ' ساعة';
                        }
                    }
                    $iconColor = match ($row['color']) {
                        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
                        'primary' => 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300',
                        'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
                        'info'    => 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
                        default   => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300',
                    };
                @endphp
                <li class="relative">
                    <span class="absolute -start-[34px] flex h-7 w-7 items-center justify-center rounded-full ring-4 ring-white dark:ring-gray-900 {{ $iconColor }}">
                        @svg($row['icon'], 'h-4 w-4')
                    </span>
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <p class="text-sm font-bold text-gray-950 dark:text-white">{{ $row['label'] }}</p>
                        <p class="font-mono text-xs text-gray-500 dark:text-gray-400">
                            {{ $row['time']->format('Y-m-d H:i:s') }}
                        </p>
                        @if ($delta)
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                {{ $delta }}
                            </span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
