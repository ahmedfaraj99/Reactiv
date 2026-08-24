<x-filament-panels::page>
    <div
        wire:poll.5s="refreshStatus"
        class="mx-auto max-w-lg text-center py-16"
    >
        @php
            // Any transition into an approved state means the middleware
            // will let us through on the very next request — just reload
            // so we go home.
            $shouldRedirect = $currentStatus === \App\Models\SessionRequest::STATUS_APPROVED;
        @endphp

        @if ($shouldRedirect)
            <div class="text-2xl font-bold text-emerald-600">تمت الموافقة — جارٍ التحويل…</div>
            <script>setTimeout(() => location.href = '/app', 500);</script>
        @elseif ($currentStatus === \App\Models\SessionRequest::STATUS_DENIED)
            <div class="text-6xl mb-4">⛔</div>
            <div class="text-2xl font-bold text-rose-600 mb-2">تم رفض الطلب</div>
            <div class="text-gray-500 mb-6">راجع المشرف لمعرفة السبب.</div>
            <a href="/app/logout" class="fi-btn fi-btn-color-gray inline-block px-4 py-2 rounded-lg bg-gray-200">تسجيل الخروج</a>
        @elseif ($currentStatus === \App\Models\SessionRequest::STATUS_REVOKED
                 || $currentStatus === \App\Models\SessionRequest::STATUS_EXPIRED)
            <div class="text-6xl mb-4">⏱️</div>
            <div class="text-2xl font-bold text-amber-600 mb-2">انتهت جلستك</div>
            <div class="text-gray-500 mb-6">تم إنشاء طلب جديد — بانتظار موافقة المشرف.</div>
            <div class="animate-pulse text-sm text-gray-400">يتم التحقق كل 5 ثوانٍ…</div>
        @else
            <div class="text-6xl mb-4 animate-pulse">⏳</div>
            <div class="text-2xl font-bold text-gray-700 mb-2">بانتظار موافقة المشرف</div>
            <div class="text-gray-500 mb-4">أُرسل طلب الدخول {{ $lastRequestedAt ?? 'الآن' }}.</div>
            <div class="text-gray-500 mb-6">لا يمكنك تصفح التطبيق قبل الموافقة. اترك هذه الشاشة مفتوحة.</div>
            <div class="animate-pulse text-sm text-gray-400">يتم التحقق تلقائياً كل 5 ثوانٍ…</div>
            <div class="mt-8">
                <a href="/app/logout" class="text-sm text-gray-400 hover:text-gray-600">تسجيل الخروج</a>
            </div>
        @endif
    </div>
</x-filament-panels::page>
