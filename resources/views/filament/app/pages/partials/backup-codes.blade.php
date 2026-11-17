{{--
    One platform's backup-code pair on the activation page. $code1/$code2
    are null until the employee may see them (approval, or no 2FA seed for
    that platform). Backup codes are short strings — no chunking, no
    per-digit color, no read-aloud controls: the employee reads them once
    when TOTP is unusable and the decoration only adds noise.
--}}
<div>
    <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
    @if ($code1 !== null)
        <div class="grid grid-cols-1 gap-2">
            <code dir="ltr" class="block w-full select-all rounded-lg bg-white px-3 py-2 font-mono text-gray-950 ring-1 ring-inset ring-gray-200 dark:bg-white/5 dark:text-gray-100 dark:ring-white/10">{{ $code1 }}</code>
            <code dir="ltr" class="block w-full select-all rounded-lg bg-white px-3 py-2 font-mono text-gray-950 ring-1 ring-inset ring-gray-200 dark:bg-white/5 dark:text-gray-100 dark:ring-white/10">{{ $code2 }}</code>
        </div>
    @else
        <div class="rounded-md border border-dashed border-gray-300 bg-gray-50 p-3 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
            مخفية — اضغط "طلب Backup Codes" أدناه فقط إذا فشل TOTP، وينتظر موافقة المشرف/المدير.
        </div>
    @endif
</div>
