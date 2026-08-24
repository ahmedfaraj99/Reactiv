<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التحقق بخطوتين — FC27AC</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
    <div class="max-w-sm w-full bg-white rounded-2xl shadow-xl overflow-hidden">
        <div class="bg-rose-600 px-6 py-5">
            <h1 class="text-white text-xl font-bold">التحقق بخطوتين</h1>
            <p class="text-rose-100 text-sm mt-1">أدخل الرمز من Google Authenticator</p>
        </div>

        <div class="p-6 space-y-5">
            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                    <p class="text-sm text-red-800">{{ $errors->first() }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('2fa.verify.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="one_time_password" class="block text-sm font-bold text-gray-900 mb-2">
                        الرمز (6 أرقام)
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
                        class="w-full text-center text-2xl tracking-widest font-mono border-2 border-gray-300 rounded-lg px-4 py-3 focus:border-rose-600 focus:outline-none"
                        placeholder="000000"
                    >
                </div>

                <button type="submit"
                    class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold py-3 rounded-lg transition">
                    تحقق
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
