@php
    /** @var \App\Models\ClubCreation\Batch $batch */
@endphp

<div class="space-y-3">
    <div class="text-sm text-gray-500">
        الرابط:
        <code class="text-xs" dir="ltr">{{ $batch->publicUrl() }}</code>
    </div>

    <table class="w-full text-sm border-collapse">
        <thead>
            <tr class="text-right border-b">
                <th class="p-2">#</th>
                <th class="p-2">البريد</th>
                <th class="p-2">كلمة المرور</th>
                <th class="p-2">الحالة</th>
                <th class="p-2">تم في</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($batch->accounts as $acc)
                <tr class="border-b">
                    <td class="p-2">{{ $acc->id }}</td>
                    <td class="p-2" dir="ltr">{{ $acc->email }}</td>
                    <td class="p-2" dir="ltr">{{ $acc->password }}</td>
                    <td class="p-2">
                        @switch($acc->status)
                            @case('available') متاح @break
                            @case('assigned') مُسنَد @break
                            @case('done') مكتمل @break
                            @case('exported') مُصدَّر @break
                            @default {{ $acc->status }}
                        @endswitch
                    </td>
                    <td class="p-2">{{ $acc->done_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
