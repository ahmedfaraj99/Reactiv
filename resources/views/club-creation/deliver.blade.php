<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $batch->account_count }} حساب جاهز</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Tajawal', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f4f6fa;
            color: #1e293b;
            line-height: 1.6;
            padding: 16px;
            min-height: 100vh;
        }
        .wrap { max-width: 560px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 20px;
            border-radius: 14px;
            margin-bottom: 20px;
            text-align: center;
        }
        .header h1 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
        .header p { font-size: 14px; opacity: 0.9; }
        .steps {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        .steps h3 { font-size: 15px; margin-bottom: 10px; color: #334155; }
        .steps ol { padding-inline-start: 20px; font-size: 14px; color: #475569; }
        .steps li { margin-bottom: 4px; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 14px;
            border: 1px solid #e2e8f0;
            transition: opacity 0.3s, border-color 0.3s;
        }
        .card.done { opacity: 0.55; border-color: #10b981; background: #f0fdf4; }
        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .card-head .idx { font-size: 14px; color: #64748b; font-weight: 500; }
        .badge {
            font-size: 12px;
            padding: 3px 10px;
            border-radius: 999px;
            background: #e0e7ff;
            color: #4338ca;
        }
        .badge.done { background: #d1fae5; color: #047857; }
        .field { margin-bottom: 10px; }
        .field label {
            display: block;
            font-size: 12px;
            color: #64748b;
            margin-bottom: 4px;
        }
        .field-value {
            display: flex;
            align-items: stretch;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            overflow: hidden;
            direction: ltr;
        }
        .field-value input {
            flex: 1;
            padding: 10px 12px;
            border: none;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            background: #f8fafc;
            color: #0f172a;
            outline: none;
            min-width: 0;
        }
        .field-value button {
            border: none;
            border-inline-start: 1px solid #cbd5e1;
            background: white;
            padding: 0 16px;
            font-family: 'Tajawal', sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            color: #4f46e5;
            transition: background 0.15s;
            white-space: nowrap;
        }
        .field-value button:hover { background: #eef2ff; }
        .field-value button.copied { background: #10b981; color: white; }
        .done-btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: #4f46e5;
            color: white;
            font-family: 'Tajawal', sans-serif;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.15s;
        }
        .done-btn:hover { background: #4338ca; }
        .done-btn:disabled { background: #94a3b8; cursor: not-allowed; }
        .card.done .done-btn { background: #10b981; }
        .totp-btn {
            width: 100%;
            padding: 10px;
            border: 1px solid #4f46e5;
            border-radius: 8px;
            background: white;
            color: #4f46e5;
            font-family: 'Tajawal', sans-serif;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .totp-btn.warn { border-color: #d97706; color: #b45309; background: #fffbeb; }
        .totp-btn:disabled { border-color: #cbd5e1; color: #94a3b8; background: #f8fafc; cursor: not-allowed; }
        .totp [hidden] { display: none !important; }
        .totp-value { margin-bottom: 6px; }
        .totp-value input { font-size: 22px; font-weight: 700; letter-spacing: 4px; text-align: center; }
        .totp-timer { font-size: 12px; color: #64748b; margin-bottom: 6px; text-align: center; }
        .lang-switch {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin-bottom: 12px;
        }
        .lang-switch button {
            border: 1px solid #cbd5e1;
            background: white;
            color: #475569;
            border-radius: 999px;
            padding: 4px 14px;
            font-family: 'Tajawal', sans-serif;
            font-size: 13px;
            cursor: pointer;
        }
        .lang-switch button.active { background: #4f46e5; border-color: #4f46e5; color: white; }
        .foot {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 24px;
            padding: 8px;
        }
    </style>
</head>
<body>
@php($platformLabel = $batch->platformLabel())
<div class="wrap">
    <div class="lang-switch" role="group" aria-label="Language">
        <button type="button" data-lang="ar" onclick="setLang('ar')">العربية</button>
        <button type="button" data-lang="en" onclick="setLang('en')">English</button>
    </div>

    <div class="header">
        <h1 data-i18n="heading">{{ $batch->account_count }} حساب جاهز للتفعيل</h1>
        <p><span data-i18n="hello">مرحباً</span> {{ $batch->recipient }}</p>
    </div>

    <div class="steps">
        <h3 data-i18n="steps_title">الخطوات لكل حساب:</h3>
        <ol>
            <li data-i18n="step_login">ادخل البريد والرمز على جهاز {{ $platformLabel }}</li>
            <li data-i18n="step_code">عند طلب كود التحقق على الجهاز اضغط "توليد كود {{ $platformLabel }}" وأدخله فوراً</li>
            <li data-i18n="step_open">افتح FIFA / EA Sports FC</li>
            <li data-i18n="step_ut">ادخل Ultimate Team</li>
            <li data-i18n="step_ea">عندما يطلب EA كود التحقق اضغط "توليد كود EA" وأدخله فوراً</li>
            <li data-i18n="step_club">أنشئ نادياً (Create Club)</li>
            <li data-i18n="step_done">اضغط زر "تم" أدناه</li>
        </ol>
    </div>

    @foreach ($accounts as $i => $account)
        @php($assigned = $account->status === 'assigned')
        <div class="card @unless($assigned) done @endunless" id="card-{{ $account->id }}">
            <div class="card-head">
                <span class="idx" data-i18n="account_n" data-n="{{ $i + 1 }}">حساب {{ $i + 1 }} من {{ $accounts->count() }}</span>
                <span class="badge @unless($assigned) done @endunless" id="badge-{{ $account->id }}"
                      data-i18n="{{ $assigned ? 'badge_progress' : 'badge_done' }}">{{ $assigned ? 'قيد التنفيذ' : 'مكتمل ✓' }}</span>
            </div>

            <div class="field">
                <label data-i18n="label_email">بريد {{ $platformLabel }}</label>
                <div class="field-value">
                    <input type="text" value="{{ $account->email }}" readonly>
                    <button type="button" data-i18n="copy" onclick="copyValue(this)">نسخ</button>
                </div>
            </div>

            <div class="field">
                <label data-i18n="label_password">رمز {{ $platformLabel }}</label>
                <div class="field-value">
                    <input type="text" value="{{ $account->password }}" readonly>
                    <button type="button" data-i18n="copy" onclick="copyValue(this)">نسخ</button>
                </div>
            </div>

            @if ($assigned)
                @foreach (['console', 'ea'] as $kind)
                    <div class="field totp" id="totp-{{ $account->id }}-{{ $kind }}"
                         data-account-id="{{ $account->id }}"
                         data-kind="{{ $kind }}"
                         data-has-seed="{{ $account->hasTotp($kind) ? '1' : '0' }}"
                         data-used="{{ $account->totpUsed($kind) }}"
                         data-allowance="{{ $account->totpAllowance($kind) }}"
                         data-pending="{{ $account->hasPendingTotpRequest($kind) ? '1' : '0' }}">
                        <label data-i18n="label_code_{{ $kind }}"></label>
                        <div class="field-value totp-value" hidden>
                            <input type="text" value="" readonly>
                            <button type="button" data-i18n="copy" onclick="copyValue(this)">نسخ</button>
                        </div>
                        <div class="totp-timer" hidden></div>
                        <button type="button" class="totp-btn" onclick="totpClick(this)"></button>
                    </div>
                @endforeach
            @endif

            <button type="button"
                    class="done-btn"
                    data-account-id="{{ $account->id }}"
                    data-i18n="{{ $assigned ? 'done_btn' : 'done_finished' }}"
                    @unless($assigned) disabled @endunless
                    onclick="markDone(this)">{{ $assigned ? 'تم — النادي أُنشئ' : '✓ تم إنجازه' }}</button>
        </div>
    @endforeach

    <div class="foot" data-i18n="foot">بعد الانتهاء أرسل تأكيداً على Messenger</div>
</div>

<script>
    const DONE_URL_TEMPLATE = @json(url('/cc/' . $batch->token . '/done'));
    const CSRF = @json(csrf_token());

    const TOTP_URL = @json(url('/cc/' . $batch->token . '/totp'));
    const TOTP_REQUEST_URL = @json(url('/cc/' . $batch->token . '/totp-request'));
    const TOTP_STATUS_URL = @json(url('/cc/' . $batch->token . '/totp-status'));
    const totpTimers = {};
    const totpPolls = {};

    // ── Translations ────────────────────────────────────────────────
    // Players aren't all Arabic speakers. Every visible string lives
    // here; to add a language, add a block with the same keys and a
    // button in .lang-switch. {placeholders} are filled by t().
    const VARS = {
        count: {{ (int) $batch->account_count }},
        total: {{ (int) $accounts->count() }},
        platform: @json($platformLabel),
    };

    const I18N = {
        ar: {
            title: '{count} حساب جاهز',
            heading: '{count} حساب جاهز للتفعيل',
            hello: 'مرحباً',
            steps_title: 'الخطوات لكل حساب:',
            step_login: 'ادخل البريد والرمز على جهاز {platform}',
            step_code: 'عند طلب كود التحقق على الجهاز اضغط "توليد كود {platform}" وأدخله فوراً',
            step_ea: 'عندما يطلب EA كود التحقق اضغط "توليد كود EA" وأدخله فوراً',
            step_open: 'افتح FIFA / EA Sports FC',
            step_ut: 'ادخل Ultimate Team',
            step_club: 'أنشئ نادياً (Create Club)',
            step_done: 'اضغط زر "تم" أدناه',
            account_n: 'حساب {n} من {total}',
            badge_progress: 'قيد التنفيذ',
            badge_done: 'مكتمل ✓',
            label_email: 'بريد {platform}',
            label_password: 'رمز {platform}',
            label_code_console: 'كود تحقق {platform}',
            label_code_ea: 'كود تحقق EA',
            copy: 'نسخ',
            copied: 'تم النسخ',
            done_btn: 'تم — النادي أُنشئ',
            done_finished: '✓ تم إنجازه',
            foot: 'بعد الانتهاء أرسل تأكيداً على Messenger',
            totp_no_seed: 'لا يوجد كود تحقق لهذا الحساب — تواصل معنا',
            totp_generate: 'توليد كود {name} ({used}/{allowance})',
            totp_waiting: 'بانتظار موافقة المالك',
            totp_request: 'إرسال طلب كود {name} إضافي',
            totp_confirm_request: 'وصلت للحد المسموح لكود {name}. إرسال طلب كود إضافي للمالك؟',
            totp_timer: 'يختفي الكود خلال {left} ثانية',
            totp_approved: 'تمت الموافقة — تقدر تولّد كود {name} جديداً الآن.',
            totp_rejected: 'لم تتم الموافقة على طلب كود {name}.',
            err_network: 'تعذّر الاتصال — حاول مجدداً.',
            err_update: 'تعذّر التحديث — حاول مجدداً.',
            err_frozen: 'النظام متوقف مؤقتاً. حاول لاحقاً.',
            err_done: 'هذا الحساب مكتمل.',
            confirm_done: 'تأكيد إنجاز هذا الحساب؟',
        },
        en: {
            title: '{count} accounts ready',
            heading: '{count} accounts ready',
            hello: 'Hello',
            steps_title: 'Steps for each account:',
            step_login: 'Sign in on your {platform} with the email and password',
            step_code: 'When the console asks for a verification code, tap "Generate {platform} code" and enter it right away',
            step_ea: 'When EA asks for a verification code, tap "Generate EA code" and enter it right away',
            step_open: 'Open FIFA / EA Sports FC',
            step_ut: 'Go to Ultimate Team',
            step_club: 'Create a club (Create Club)',
            step_done: 'Tap the "Done" button below',
            account_n: 'Account {n} of {total}',
            badge_progress: 'In progress',
            badge_done: 'Completed ✓',
            label_email: '{platform} email',
            label_password: '{platform} password',
            label_code_console: '{platform} verification code',
            label_code_ea: 'EA verification code',
            copy: 'Copy',
            copied: 'Copied',
            done_btn: 'Done — club created',
            done_finished: '✓ Completed',
            foot: 'When finished, send a confirmation on Messenger',
            totp_no_seed: 'No verification code for this account — contact us',
            totp_generate: 'Generate {name} code ({used}/{allowance})',
            totp_waiting: 'Waiting for owner approval',
            totp_request: 'Request an extra {name} code',
            totp_confirm_request: 'You reached the {name} code limit. Send a request for an extra code to the owner?',
            totp_timer: 'Code disappears in {left} s',
            totp_approved: 'Approved — you can generate a new {name} code now.',
            totp_rejected: 'Your {name} code request was not approved.',
            err_network: 'Connection failed — please try again.',
            err_update: 'Update failed — please try again.',
            err_frozen: 'The system is temporarily paused. Try again later.',
            err_done: 'This account is already completed.',
            confirm_done: 'Confirm this account is done?',
        },
    };

    const RTL = ['ar'];
    let lang = pickLang();

    function pickLang() {
        const fromUrl = new URLSearchParams(location.search).get('lang');
        if (fromUrl && I18N[fromUrl]) return fromUrl;
        try {
            const saved = localStorage.getItem('cc_lang');
            if (saved && I18N[saved]) return saved;
        } catch (e) {}
        return (navigator.language || '').toLowerCase().startsWith('ar') ? 'ar' : 'en';
    }

    function t(key, vars) {
        let s = (I18N[lang] && I18N[lang][key]) || I18N.ar[key] || key;
        const all = Object.assign({}, VARS, vars || {});
        return s.replace(/\{(\w+)\}/g, (m, k) => (k in all ? all[k] : m));
    }

    function applyLang() {
        document.documentElement.lang = lang;
        document.documentElement.dir = RTL.includes(lang) ? 'rtl' : 'ltr';
        document.title = t('title');
        document.querySelectorAll('[data-i18n]').forEach(el => {
            el.textContent = t(el.dataset.i18n, el.dataset.n ? { n: el.dataset.n } : null);
        });
        document.querySelectorAll('.lang-switch button').forEach(b => {
            b.classList.toggle('active', b.dataset.lang === lang);
        });
        document.querySelectorAll('.totp').forEach(renderTotp);
    }

    function setLang(next) {
        if (!I18N[next]) return;
        lang = next;
        try { localStorage.setItem('cc_lang', next); } catch (e) {}
        applyLang();
    }

    // Server replies carry an error code; show it in the player's language.
    // Per-box key + translation vars: the console code is named after
    // the platform (PlayStation / Xbox), the other one is "EA".
    function boxKey(box) { return box.dataset.accountId + '-' + box.dataset.kind; }
    function boxVars(box) { return { name: box.dataset.kind === 'ea' ? 'EA' : VARS.platform }; }
    function boxUrl(base, box) { return base + '/' + box.dataset.accountId + '/' + box.dataset.kind; }

    function errorText(data) {
        const map = { frozen: 'err_frozen', done: 'err_done', no_seed: 'totp_no_seed' };
        return map[data.error] ? t(map[data.error]) : null;
    }

    // Mirrors the activation page: "generate (used/allowance)" while
    // allowance remains, then "ask the owner", then "waiting".
    function renderTotp(box) {
        const btn = box.querySelector('.totp-btn');
        const used = +box.dataset.used, allowance = +box.dataset.allowance;
        const name = boxVars(box).name;
        btn.classList.remove('warn');
        btn.disabled = false;

        if (box.dataset.hasSeed !== '1') {
            btn.textContent = t('totp_no_seed');
            btn.disabled = true;
        } else if (used < allowance) {
            btn.textContent = t('totp_generate', { name, used, allowance });
        } else if (box.dataset.pending === '1') {
            btn.textContent = t('totp_waiting');
            btn.classList.add('warn');
            btn.disabled = true;
            startPoll(box);
        } else {
            btn.textContent = t('totp_request', { name });
            btn.classList.add('warn');
        }
    }

    function applyState(box, data) {
        if (typeof data.used === 'number') box.dataset.used = data.used;
        if (typeof data.allowance === 'number') box.dataset.allowance = data.allowance;
        if (typeof data.pending === 'boolean') box.dataset.pending = data.pending ? '1' : '0';
        renderTotp(box);
    }

    function postJson(url) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        }).then(r => r.json().then(data => ({ ok: r.ok, data })));
    }

    function totpClick(btn) {
        const box = btn.closest('.totp');
        const canGenerate = +box.dataset.used < +box.dataset.allowance;

        if (!canGenerate && !confirm(t('totp_confirm_request', boxVars(box)))) return;

        btn.disabled = true;
        const url = boxUrl(canGenerate ? TOTP_URL : TOTP_REQUEST_URL, box);

        postJson(url).then(({ ok, data }) => {
            applyState(box, data);
            if (ok && data.code) showCode(box, data.code, data.seconds);
            else if (errorText(data)) alert(errorText(data));
        }).catch(() => {
            renderTotp(box);
            alert(t('err_network'));
        });
    }

    // One generation = one visible window; when it elapses the code is
    // cleared and another press (another allowance) is needed.
    function showCode(box, code, seconds) {
        const id = boxKey(box);
        const wrap = box.querySelector('.totp-value');
        const timer = box.querySelector('.totp-timer');
        const input = wrap.querySelector('input');
        input.value = code;
        wrap.hidden = false;
        timer.hidden = false;

        clearInterval(totpTimers[id]);
        const until = Date.now() + seconds * 1000;
        const tick = () => {
            const left = Math.max(0, Math.round((until - Date.now()) / 1000));
            timer.textContent = t('totp_timer', { left });
            if (left === 0) {
                clearInterval(totpTimers[id]);
                input.value = '';
                wrap.hidden = true;
                timer.hidden = true;
            }
        };
        tick();
        totpTimers[id] = setInterval(tick, 1000);
    }

    function startPoll(box) {
        const id = boxKey(box);
        if (totpPolls[id]) return;
        totpPolls[id] = setInterval(() => {
            fetch(boxUrl(TOTP_STATUS_URL, box), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (data.pending) return;
                    clearInterval(totpPolls[id]);
                    delete totpPolls[id];
                    applyState(box, data);
                    alert(t(data.can_generate ? 'totp_approved' : 'totp_rejected', boxVars(box)));
                })
                .catch(() => {});
        }, 10000);
    }

    function copyValue(btn) {
        const input = btn.parentElement.querySelector('input');
        const value = input.value;

        const setCopied = () => {
            btn.textContent = t('copied');
            btn.classList.add('copied');
            setTimeout(() => {
                btn.textContent = t('copy');
                btn.classList.remove('copied');
            }, 1400);
        };

        // Prefer the modern async API where available (HTTPS + supported
        // browser). Fall back to the legacy execCommand path for
        // in-app browsers (Messenger, older WebViews) where clipboard
        // access is often gated.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(setCopied).catch(() => legacyCopy(input, setCopied));
        } else {
            legacyCopy(input, setCopied);
        }
    }

    function legacyCopy(input, onDone) {
        input.removeAttribute('readonly');
        input.select();
        input.setSelectionRange(0, 99999);
        try { document.execCommand('copy'); onDone(); } catch (e) {}
        input.setAttribute('readonly', 'readonly');
        input.blur();
    }

    function markDone(btn) {
        if (!confirm(t('confirm_done'))) return;

        const id = btn.dataset.accountId;
        btn.disabled = true;

        fetch(DONE_URL_TEMPLATE + '/' + id, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF,
                'Accept': 'application/json',
            },
        })
        .then(r => r.json())
        .then(data => {
            const card = document.getElementById('card-' + id);
            const badge = document.getElementById('badge-' + id);
            card.classList.add('done');
            badge.classList.add('done');
            badge.dataset.i18n = 'badge_done';
            badge.textContent = t('badge_done');
            btn.dataset.i18n = 'done_finished';
            btn.textContent = t('done_finished');
            document.querySelectorAll('.totp[data-account-id="' + id + '"]').forEach(box => {
                clearInterval(totpPolls[boxKey(box)]);
                clearInterval(totpTimers[boxKey(box)]);
                box.remove();
            });
        })
        .catch(() => {
            btn.disabled = false;
            alert(t('err_update'));
        });
    }

    applyLang();
</script>
</body>
</html>
