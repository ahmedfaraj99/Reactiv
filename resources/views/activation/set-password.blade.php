<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفعيل الحساب — {{ config('app.name') }}</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 2rem 1rem; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: white; max-width: 440px; width: 100%; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        h1 { margin: 0 0 0.5rem; color: #111827; font-size: 1.5rem; }
        .subtitle { color: #6b7280; margin: 0 0 1.5rem; font-size: 0.95rem; }
        .field { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.4rem; font-weight: 600; color: #374151; font-size: 0.9rem; }
        input[type=password], input[type=text] { width: 100%; padding: 0.7rem 0.9rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; box-sizing: border-box; font-family: inherit; }
        input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
        .readonly { background: #f9fafb; color: #6b7280; }
        button { width: 100%; padding: 0.8rem; background: #6366f1; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem; font-family: inherit; }
        button:hover { background: #4f46e5; }
        .error { color: #dc2626; font-size: 0.85rem; margin-top: 0.3rem; }
        .hint { color: #6b7280; font-size: 0.8rem; margin-top: 0.3rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>تفعيل الحساب</h1>
        <p class="subtitle">مرحباً {{ $user->name }} — اختر كلمة سر جديدة لتفعيل حسابك.</p>

        {{-- request()->fullUrl() preserves the ?expires=…&signature=… query
             string. url()->current() (or route()) strips it, which makes
             the POST bounce off the 'signed' middleware silently and the
             user sees the form seem to "not do anything". --}}
        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf

            <div class="field">
                <label>البريد الإلكتروني</label>
                <input type="text" value="{{ $user->email }}" class="readonly" readonly>
            </div>

            <div class="field">
                <label for="password">كلمة السر الجديدة</label>
                <input type="password" name="password" id="password" required minlength="8" autocomplete="new-password">
                <div class="hint">8 أحرف على الأقل، تحتوي أحرفاً وأرقاماً.</div>
                @error('password')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">تأكيد كلمة السر</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required minlength="8" autocomplete="new-password">
            </div>

            <button type="submit">تفعيل الحساب</button>
        </form>
    </div>
</body>
</html>
