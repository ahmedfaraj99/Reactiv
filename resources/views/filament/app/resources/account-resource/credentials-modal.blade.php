{{--
    Manager-only credentials view. Values are rendered inside <code> blocks
    so the manager can copy them cleanly. The current TOTP codes shown are
    a snapshot at modal-open time — closing and reopening yields the current
    code, which is also written to reveal_logs on every open.
--}}
<div class="space-y-4 text-sm" dir="ltr">
    <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-warning-900 dark:border-warning-700 dark:bg-warning-900/20 dark:text-warning-100" dir="rtl">
        كل فتح لهذه النافذة يُسجَّل في سجل الكشف — لا تشارك هذه البيانات إلا مع الموظف المخصص.
    </div>

    <section class="space-y-2">
        <h3 class="font-semibold text-base" dir="rtl">PSN</h3>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-2">
            <dt class="text-gray-500" dir="rtl">البريد</dt>
            <dd><code class="select-all font-mono">{{ $psn_email }}</code></dd>

            <dt class="text-gray-500" dir="rtl">كلمة السر</dt>
            <dd><code class="select-all font-mono">{{ $psn_password }}</code></dd>

            <dt class="text-gray-500" dir="rtl">كود التحقق</dt>
            <dd>
                <code class="select-all font-mono text-lg tracking-widest">{{ $psn_totp['code'] }}</code>
                <span class="ms-2 text-xs text-gray-500">({{ $psn_totp['remaining'] }}s)</span>
            </dd>
        </dl>
    </section>

    <section class="space-y-2">
        <h3 class="font-semibold text-base" dir="rtl">EA</h3>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-2">
            <dt class="text-gray-500" dir="rtl">البريد</dt>
            <dd><code class="select-all font-mono">{{ $ea_email }}</code></dd>

            <dt class="text-gray-500" dir="rtl">كلمة السر</dt>
            <dd><code class="select-all font-mono">{{ $ea_password }}</code></dd>

            <dt class="text-gray-500" dir="rtl">كود التحقق</dt>
            <dd>
                <code class="select-all font-mono text-lg tracking-widest">{{ $ea_totp['code'] }}</code>
                <span class="ms-2 text-xs text-gray-500">({{ $ea_totp['remaining'] }}s)</span>
            </dd>

            @if ($backup1 !== null || $backup2 !== null)
                <dt class="text-gray-500" dir="rtl">أكواد احتياط</dt>
                <dd class="space-x-2 rtl:space-x-reverse">
                    @if ($backup1) <code class="select-all font-mono">{{ $backup1 }}</code> @endif
                    @if ($backup2) <code class="select-all font-mono">{{ $backup2 }}</code> @endif
                </dd>
            @endif
        </dl>
    </section>
</div>
