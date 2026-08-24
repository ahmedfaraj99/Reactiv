<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفعيل التحقق بخطوتين — FC27AC</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4 py-8">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl overflow-hidden">
        <div class="bg-rose-600 px-6 py-5">
            <h1 class="text-white text-xl font-bold">تفعيل التحقق بخطوتين</h1>
            <p class="text-rose-100 text-sm mt-1">حماية إجبارية قبل استخدام النظام</p>
        </div>

        <div class="p-6 space-y-5">
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                <p class="text-sm font-bold text-amber-900 mb-1">قبل البدء</p>
                <p class="text-xs text-amber-800 leading-relaxed">
                    ثبّت تطبيق <strong>Google Authenticator</strong> على هاتفك من
                    <a class="underline" href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Google Play</a>
                    أو
                    <a class="underline" href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank">App Store</a>.
                </p>
            </div>

            <div>
                <p class="text-sm font-bold text-gray-900 mb-2">الخطوة 1: امسح الرمز</p>
                <div class="bg-gray-50 rounded-lg p-4 flex justify-center">
                    {!! $qrSvg !!}
                </div>
                <p class="text-xs text-gray-500 mt-2 text-center">
                    أو أدخل هذا الرمز يدوياً:
                    <code class="bg-gray-100 px-2 py-1 rounded text-gray-800 select-all">{{ $secret }}</code>
                </p>
            </div>

            <form method="POST" action="{{ route('2fa.setup.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="one_time_password" class="block text-sm font-bold text-gray-900 mb-2">
                        الخطوة 2: أدخل الرمز الظاهر في التطبيق (6 أرقام)
                    </label>
                    <input
                        type="text"
                        name="one_time_password"
                        id="one_time_password"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        required
                        autofocus
                        class="w-full text-center text-2xl tracking-widest font-mono border-2 rounded-lg px-4 py-3 focus:border-rose-600 focus:outline-none @error('one_time_password') border-red-500 @else border-gray-300 @enderror"
                        placeholder="000000"
                    >
                    @error('one_time_password')
                        <p class="text-sm text-red-600 mt-2">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                    class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold py-3 rounded-lg transition">
                    تفعيل والدخول
                </button>
            </form>

            <div class="pt-4 border-t border-gray-100">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-xs text-gray-500 hover:text-gray-700">
                        تسجيل الخروج
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
