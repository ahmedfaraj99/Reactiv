<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقرير أسبوعي — {{ $office->name }} — {{ $period['iso_week'] }}</title>
    <style>
        @page { margin: 22mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; direction: rtl; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 20px 0 8px; border-bottom: 1px solid #999; padding-bottom: 2px; }
        .muted { color: #666; }
        .kpis { display: table; width: 100%; border-collapse: collapse; margin-top: 12px; }
        .kpi { display: table-cell; border: 1px solid #ccc; padding: 8px 10px; text-align: center; width: 20%; }
        .kpi .n { font-size: 20px; font-weight: bold; }
        .kpi .l { font-size: 10px; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: right; }
        th { background: #f2f2f2; font-weight: bold; }
        tr:nth-child(even) td { background: #fafafa; }
        .sev-high { color: #b91c1c; font-weight: bold; }
        .sev-medium { color: #a16207; }
        .sev-low { color: #4b5563; }
        .footer { margin-top: 24px; font-size: 10px; color: #888; border-top: 1px solid #ddd; padding-top: 6px; }
        .empty { color: #888; font-style: italic; padding: 8px; }
    </style>
</head>
<body>
    <h1>تقرير أسبوعي — {{ $office->name }}</h1>
    <div class="muted">
        الفترة: {{ $period['start']->format('Y-m-d') }} إلى {{ $period['end']->format('Y-m-d') }}
        (أسبوع {{ $period['iso_week'] }})
        {{-- Print time is when the PDF was generated, useful when the same
             report is regenerated after a data correction. --}}
        · تاريخ الإصدار: {{ now()->format('Y-m-d H:i') }}
    </div>

    <div class="kpis">
        <div class="kpi"><div class="n">{{ $totals['assigned'] }}</div><div class="l">إجمالي التعيينات</div></div>
        <div class="kpi"><div class="n">{{ $totals['completed'] }}</div><div class="l">مكتملة</div></div>
        <div class="kpi"><div class="n">{{ $totals['failed'] }}</div><div class="l">فاشلة</div></div>
        <div class="kpi"><div class="n">{{ $accounts_used }}</div><div class="l">حسابات مُستخدَمة</div></div>
        <div class="kpi"><div class="n">{{ $matches_total }}</div><div class="l">مباريات مُنجَزة</div></div>
    </div>

    <h2>أداء الموظفين</h2>
    @if ($employees->isEmpty())
        <div class="empty">لا توجد تعيينات هذا الأسبوع.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>الموظف</th>
                    <th>معيَّنة</th>
                    <th>مكتملة</th>
                    <th>فاشلة</th>
                    <th>مباريات</th>
                    <th>نسبة النجاح</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($employees as $e)
                @php
                    $rate = $e->assigned > 0 ? round($e->completed / $e->assigned * 100) : 0;
                @endphp
                <tr>
                    <td>{{ $e->name }}</td>
                    <td>{{ $e->assigned }}</td>
                    <td>{{ $e->completed }}</td>
                    <td>{{ $e->failed }}</td>
                    <td>{{ $e->matches }}</td>
                    <td>{{ $rate }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>الحالات المشبوهة ({{ $suspicious['alerts'] }})</h2>
    @if ($suspicious['alerts'] === 0)
        <div class="empty">لا توجد تنبيهات لهذا الأسبوع.</div>
    @else
        <table>
            <thead><tr><th>النوع</th><th>العدد</th></tr></thead>
            <tbody>
            @foreach ($suspicious['by_type'] as $type => $count)
                <tr><td>{{ $type }}</td><td>{{ $count }}</td></tr>
            @endforeach
            </tbody>
        </table>

        <h2>آخر 10 تنبيهات</h2>
        <table>
            <thead>
                <tr><th>التاريخ</th><th>النوع</th><th>الخطورة</th><th>الرسالة</th></tr>
            </thead>
            <tbody>
            @foreach ($suspicious['sample'] as $a)
                <tr>
                    <td>{{ $a->created_at->format('m-d H:i') }}</td>
                    <td>{{ $a->type }}</td>
                    <td class="sev-{{ $a->severity }}">{{ $a->severity }}</td>
                    <td>{{ $a->message }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        FC27AC · تقرير آلي — لا تعدّل يدوياً · مولَّد {{ now()->format('Y-m-d H:i:s') }}
    </div>
</body>
</html>
