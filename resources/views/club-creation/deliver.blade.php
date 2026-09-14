<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>حسابات جاهزة — {{ $batch->account_count }} حساب</title>
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
<div class="wrap">
    <div class="header">
        <h1>{{ $batch->account_count }} حساب جاهز للتفعيل</h1>
        <p>مرحباً {{ $batch->recipient }}</p>
    </div>

    <div class="steps">
        <h3>الخطوات لكل حساب:</h3>
        <ol>
            <li>ادخل بيانات الحساب على PlayStation</li>
            <li>افتح FIFA / EA Sports FC</li>
            <li>ادخل Ultimate Team</li>
            <li>أنشئ نادياً (Create Club)</li>
            <li>اضغط زر "تم" أدناه</li>
        </ol>
    </div>

    @foreach ($accounts as $i => $account)
        <div class="card @if($account->status !== 'assigned') done @endif" id="card-{{ $account->id }}">
            <div class="card-head">
                <span class="idx">حساب {{ $i + 1 }} من {{ $accounts->count() }}</span>
                <span class="badge @if($account->status !== 'assigned') done @endif" id="badge-{{ $account->id }}">
                    @if($account->status === 'assigned') قيد التنفيذ
                    @else مكتمل ✓
                    @endif
                </span>
            </div>

            <div class="field">
                <label>البريد الإلكتروني</label>
                <div class="field-value">
                    <input type="text" value="{{ $account->email }}" readonly>
                    <button type="button" onclick="copyValue(this)">نسخ</button>
                </div>
            </div>

            <div class="field">
                <label>كلمة المرور</label>
                <div class="field-value">
                    <input type="text" value="{{ $account->password }}" readonly>
                    <button type="button" onclick="copyValue(this)">نسخ</button>
                </div>
            </div>

            <button type="button"
                    class="done-btn"
                    data-account-id="{{ $account->id }}"
                    @if($account->status !== 'assigned') disabled @endif
                    onclick="markDone(this)">
                @if($account->status === 'assigned') تم — النادي أُنشئ
                @else ✓ تم إنجازه
                @endif
            </button>
        </div>
    @endforeach

    <div class="foot">
        بعد الانتهاء أرسل تأكيداً على Messenger
    </div>
</div>

<script>
    const DONE_URL_TEMPLATE = @json(url('/cc/' . $batch->token . '/done'));
    const CSRF = @json(csrf_token());

    function copyValue(btn) {
        const input = btn.parentElement.querySelector('input');
        const value = input.value;

        const setCopied = () => {
            const original = btn.textContent;
            btn.textContent = 'تم النسخ';
            btn.classList.add('copied');
            setTimeout(() => {
                btn.textContent = original;
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
        if (!confirm('تأكيد إنجاز هذا الحساب؟')) return;

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
            badge.textContent = 'مكتمل ✓';
            btn.textContent = '✓ تم إنجازه';
        })
        .catch(() => {
            btn.disabled = false;
            alert('تعذّر التحديث — حاول مجدداً.');
        });
    }
</script>
</body>
</html>
