<x-filament-panels::page>
    @php $rows = $this->rows(); @endphp

    @if (empty($rows))
        <div class="text-center py-16 text-gray-500">
            <div class="text-6xl mb-4">✅</div>
            <div class="text-lg">لا توجد حسابات مكتملة جاهزة للتسليم حالياً.</div>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr class="text-right">
                        <th class="px-4 py-3 font-semibold">العميل</th>
                        <th class="px-4 py-3 font-semibold">البريد</th>
                        <th class="px-4 py-3 font-semibold text-center">حسابات جاهزة</th>
                        <th class="px-4 py-3 font-semibold text-end">إجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $row['client']->name }}</td>
                            <td class="px-4 py-3 text-gray-500 text-xs">{{ $row['client']->email ?? '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="fi-badge inline-flex items-center rounded-md bg-gray-100 dark:bg-white/10 px-2 py-1 text-sm font-medium">
                                    {{ $row['count'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-end whitespace-nowrap">
                                <div class="inline-flex gap-2">
                                    {{ ($this->exportAction)(['client' => $row['client']->id]) }}
                                    {{ ($this->wipeAction)(['client' => $row['client']->id]) }}
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="text-sm text-gray-500 mt-4">
            <div>ماذا يُمسح؟ الحقول الحساسة فقط: كلمات السر و رموز 2FA و أكواد الاحتياط.</div>
            <div>ماذا يبقى؟ البريد، وقت التفعيل، الموظف، صورة الإثبات، وسجل الكشف الكامل — للمراجعة.</div>
        </div>
    @endif
</x-filament-panels::page>
