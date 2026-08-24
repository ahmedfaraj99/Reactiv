<x-filament-panels::page>
    @php
        $user = auth()->user();
        $watermark = trim(($user->name ?? '').' • '.$user->id.' • '.request()->ip().' • '.now()->format('Y-m-d H:i'));
    @endphp

    {{-- Dynamic watermark overlay — deters screenshot leakage --}}
    <style>
        .fc-watermark {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 40;
            overflow: hidden;
            opacity: 0.15;
        }
        .fc-watermark span {
            position: absolute;
            transform: rotate(-30deg);
            white-space: nowrap;
            font-family: monospace;
            font-size: 12px;
            color: #64748b;
        }
        .fc-step-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        /* Credential display — sized for reading across the room while
           typing on a PS controller, and disambiguated per character.
           Base size is deliberately larger than the surrounding UI; the
           zoom control multiplies from here. */
        .fc-cred { font-size: 1.125rem; line-height: 1.6; letter-spacing: 0.01em; }
        [data-cred-size="lg"] .fc-cred { font-size: 1.5rem; }
        [data-cred-size="xl"] .fc-cred { font-size: 2rem; }

        .fc-cred-chunk + .fc-cred-chunk { margin-inline-start: 0.6em; }

        /* Digits vs confusable letters — every 0/O/1/l/I/S/Z/B/G is
           tagged so a glance is enough to know which family it belongs
           to, without staring at the character. Digits get amber, the
           confusable letters get blue. */
        .fc-cred-digit {
            background-color: rgb(254 243 199);
            color: rgb(120 53 15);
            border-radius: 0.2em;
            padding: 0 0.15em;
        }
        :root:not([data-theme="light"]) .fc-cred-digit,
        :root[data-theme="dark"] .fc-cred-digit {
            background-color: rgba(245 158 11 / 0.25);
            color: rgb(252 211 77);
        }
        .fc-cred-letter {
            outline: 1px dashed rgb(59 130 246);
            outline-offset: 1px;
            border-radius: 0.15em;
        }
        :root:not([data-theme="light"]) .fc-cred-letter,
        :root[data-theme="dark"] .fc-cred-letter {
            outline-color: rgb(96 165 250);
        }

        /* Speak button pulses briefly while reading so the employee knows
           it's actually working, since the audio may be muted. */
        .fc-cred-speak.is-speaking { animation: fc-pulse 0.6s infinite; }
        @keyframes fc-pulse {
            0%, 100% { opacity: 1; }
            50%      { opacity: 0.5; }
        }

        /* Big-screen mode — the credential covers the whole viewport at
           enormous size on a near-black background. Employee props the
           phone on the desk and reads from across the room. */
        .fc-bigscreen {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: #020617;
            color: #f8fafc;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 5vh 4vw;
            cursor: pointer;
            overflow: hidden;
        }
        .fc-bigscreen[hidden] { display: none; }
        .fc-bigscreen-value {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-weight: 700;
            font-size: clamp(2.5rem, 12vw, 8rem);
            line-height: 1.15;
            letter-spacing: 0.03em;
            text-align: center;
            word-break: break-all;
            /* Reuse the same digit/confusable-letter styling — the
               overlay inherits classes from the cloned inner HTML. */
        }
        .fc-bigscreen-hint {
            position: absolute;
            bottom: 2vh;
            font-size: 0.75rem;
            opacity: 0.4;
        }
        /* Digit highlights need to work on the dark background too. */
        .fc-bigscreen .fc-cred-digit {
            background-color: rgba(245, 158, 11, 0.35);
            color: #fef3c7;
        }
        .fc-bigscreen .fc-cred-letter {
            outline-color: #60a5fa;
        }
        .fc-bigscreen .fc-cred-chunk + .fc-cred-chunk {
            margin-inline-start: 0.5em;
        }
    </style>
    <div class="fc-watermark" aria-hidden="true">
        @for ($row = 0; $row < 12; $row++)
            @for ($col = 0; $col < 8; $col++)
                <span style="top: {{ $row * 9 }}vh; left: {{ $col * 14 - 5 }}vw;">{{ $watermark }}</span>
            @endfor
        @endfor
    </div>

    <div class="mx-auto w-full max-w-6xl" style="position: relative; z-index: 1;">
        @if ($blockingLock)
            <div class="flex flex-col items-center justify-center rounded-2xl bg-white p-12 text-center ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-rose-100 dark:bg-rose-500/10">
                    <x-heroicon-o-lock-closed class="h-8 w-8 text-rose-600 dark:text-rose-400" />
                </div>
                <h2 class="text-lg font-bold text-gray-950 dark:text-white">ممنوع — أنت مقفول على حساب #{{ $blockingLock->account_id }}</h2>
                <p class="mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">
                    لا يمكنك فتح هذا الحساب حتى تكمل حساب #{{ $blockingLock->account_id }} أولاً — بإرسال إثبات الإنجاز أو تسجيل بيانات خطأ.
                </p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <a href="{{ route('filament.app.pages.activation', ['tenant' => filament()->getTenant()->slug, 'assignment' => $blockingLock->id]) }}"
                       class="rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-primary-700">
                        اذهب لحساب #{{ $blockingLock->account_id }}
                    </a>
                    <a href="{{ \App\Filament\App\Pages\MyAccounts::getUrl() }}"
                       class="rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-bold text-gray-600 transition hover:bg-gray-50 dark:border-white/10 dark:text-gray-400 dark:hover:bg-white/5">
                        العودة إلى حساباتي
                    </a>
                </div>
            </div>
        @else
        @if ($assignment->account->requiresMatches())
            <div class="mb-6 flex gap-3 rounded-2xl bg-purple-50 p-4 ring-1 ring-purple-200 dark:bg-purple-500/10 dark:ring-purple-500/30">
                <x-heroicon-o-trophy class="h-6 w-6 flex-shrink-0 text-purple-600 dark:text-purple-400" />
                <div>
                    <p class="text-sm font-bold text-purple-900 dark:text-purple-300">هذا الحساب يتطلب لعب {{ $assignment->account->matches_required }} مباريات بعد التفعيل</p>
                    <p class="mt-1 text-sm text-purple-800 dark:text-purple-200">
                        صورة الإثبات لازم تُظهر أرباح المباريات (Match Rewards) من داخل اللعبة، وليس فقط شاشة تسجيل الدخول.
                    </p>
                </div>
            </div>
        @endif

        @if ($assignment->status === \App\Models\AccountAssignment::STATUS_IN_PROGRESS && $assignment->rejection_reason)
            <div class="mb-6 flex gap-3 rounded-2xl bg-rose-50 p-4 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:ring-rose-500/30">
                <x-heroicon-o-exclamation-triangle class="h-6 w-6 flex-shrink-0 text-rose-600 dark:text-rose-400" />
                <div>
                    <p class="text-sm font-bold text-rose-900 dark:text-rose-300">المشرف رفض الإثبات السابق</p>
                    <p class="mt-1 text-sm text-rose-800 dark:text-rose-200">{{ $assignment->rejection_reason }}</p>
                </div>
            </div>
        @endif

        {{-- Mobile-only compact summary strip. The full sticky sidebar lives
             on the right at lg+ but is hidden on phones because pushing it
             below all three steps means the employee scrolls past the whole
             page to see which account they're even working on. --}}
        <div class="mb-4 flex items-center gap-3 rounded-2xl bg-white p-3 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 lg:hidden">
            <a href="{{ \App\Filament\App\Pages\MyAccounts::getUrl() }}"
               class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 transition hover:bg-gray-200 dark:bg-white/5 dark:text-gray-400 dark:hover:bg-white/10"
               aria-label="العودة إلى حساباتي">
                <x-heroicon-m-arrow-right class="h-5 w-5" />
            </a>
            <div class="min-w-0 flex-1">
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">حساب رقم</p>
                <p class="font-mono text-lg font-bold text-gray-950 dark:text-white">#{{ $assignment->account->id }}</p>
            </div>
            <div class="text-end">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    @switch($assignment->status)
                        @case(\App\Models\AccountAssignment::STATUS_PENDING) بانتظارك @break
                        @case(\App\Models\AccountAssignment::STATUS_IN_PROGRESS) قيد التنفيذ @break
                        @case(\App\Models\AccountAssignment::STATUS_AWAITING_REVIEW) بانتظار المراجعة @break
                        @case(\App\Models\AccountAssignment::STATUS_COMPLETED) مكتمل @break
                        @case(\App\Models\AccountAssignment::STATUS_FAILED) فشل @break
                        @default {{ $assignment->status }}
                    @endswitch
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $assignment->assigned_at?->diffForHumans() }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            {{-- Main column: the three steps --}}
            <div class="space-y-5 lg:col-span-2">
                {{-- Step 1: Credentials --}}
                <div class="space-y-4 rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:p-6" data-fc-creds-container>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="fc-step-num bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">١</span>
                            <h3 class="text-lg font-bold text-gray-950 dark:text-white">بيانات الدخول</h3>
                        </div>
                        {{-- Font-size zoom for the credential blocks. Employees who
                             work on a PS across the desk from their laptop crank
                             this up; those who work up close leave it small.
                             Choice is remembered per browser via localStorage.
                             Touch-sized (h-9 min-w-9) so it's usable on phones. --}}
                        <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5" role="group" aria-label="حجم النص">
                            <button type="button" data-fc-cred-size="md" class="fc-cred-size-btn flex h-9 min-w-9 items-center justify-center rounded px-2 text-xs font-bold text-gray-600 dark:text-gray-400">أ</button>
                            <button type="button" data-fc-cred-size="lg" class="fc-cred-size-btn flex h-9 min-w-9 items-center justify-center rounded px-2 text-sm font-bold text-gray-600 dark:text-gray-400">أ+</button>
                            <button type="button" data-fc-cred-size="xl" class="fc-cred-size-btn flex h-9 min-w-9 items-center justify-center rounded px-2 text-base font-bold text-gray-600 dark:text-gray-400">أ++</button>
                        </div>
                    </div>

                    @if ($credentialsBlockedByWorkHours)
                        <div class="flex gap-3 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
                            <x-heroicon-o-clock class="h-6 w-6 flex-shrink-0 text-amber-600 dark:text-amber-400" />
                            <p class="text-sm text-amber-800 dark:text-amber-200">بيانات الدخول متاحة فقط خلال ساعات العمل المحددة.</p>
                        </div>
                    @else
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <span class="fc-cred-digit">٠١٢</span> = رقم،
                            <span class="fc-cred-letter">Ol</span> = حرف يُخلط برقم،
                            زر <x-heroicon-m-speaker-wave class="inline h-3.5 w-3.5" /> يقرأ الحروف واحداً واحداً.
                        </p>

                        {{-- Stacked full-width: PSN then EA. Emails/passwords
                             can be long (some tenants use 30+ char values),
                             and side-by-side halved the room so the value
                             either wrapped or scrolled — bad for reading
                             across a desk. Vertical stack costs one extra
                             scroll but every credential gets the whole card. --}}
                        <div class="space-y-4">
                            <div class="space-y-3 rounded-xl bg-blue-50/50 p-4 ring-1 ring-blue-200 dark:bg-blue-500/5 dark:ring-blue-500/20">
                                <span class="inline-flex items-center rounded-lg bg-blue-100 px-2 py-1 text-xs font-bold text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-300 dark:ring-blue-400/30">PSN</span>
                                <div>
                                    <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">البريد</p>
                                    @include('filament.app.pages.partials.credential', ['value' => $revealedPsnEmail, 'id' => 'cred-psn-email', 'chunked' => false])
                                </div>
                                <div>
                                    <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">كلمة المرور</p>
                                    @include('filament.app.pages.partials.credential', ['value' => $revealedPsnPassword, 'id' => 'cred-psn-password'])
                                </div>
                            </div>

                            <div class="space-y-3 rounded-xl bg-orange-50/50 p-4 ring-1 ring-orange-200 dark:bg-orange-500/5 dark:ring-orange-500/20">
                                <span class="inline-flex items-center rounded-lg bg-orange-100 px-2 py-1 text-xs font-bold text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-400/10 dark:text-orange-300 dark:ring-orange-400/30">EA</span>
                                <div>
                                    <p class="mb-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                        البريد
                                        @if ($assignment->account->ea_email === null)
                                            <span class="text-orange-600 dark:text-orange-400">(نفس بريد PSN)</span>
                                        @endif
                                    </p>
                                    @include('filament.app.pages.partials.credential', ['value' => $revealedEaEmail, 'id' => 'cred-ea-email', 'chunked' => false])
                                </div>
                                <div>
                                    <p class="mb-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                        كلمة المرور
                                        @if ($assignment->account->ea_password === null)
                                            <span class="text-orange-600 dark:text-orange-400">(نفس رمز PSN)</span>
                                        @endif
                                    </p>
                                    @include('filament.app.pages.partials.credential', ['value' => $revealedEaPassword, 'id' => 'cred-ea-password'])
                                </div>

                                @if ($assignment->account->hasEaBackupCodes())
                                    <div>
                                        <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">أكواد احتياط EA</p>
                                        <div class="grid grid-cols-1 gap-2">
                                            @include('filament.app.pages.partials.credential', ['value' => $revealedEaBackupCode1, 'id' => 'cred-ea-backup-1'])
                                            @include('filament.app.pages.partials.credential', ['value' => $revealedEaBackupCode2, 'id' => 'cred-ea-backup-2'])
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Credentials-block enhancers: size persistence + per-char speech.
                     Attached once at document level so a Livewire re-render of the
                     activation page doesn't detach the handlers or re-bind twice. --}}
                <script>
                    (function () {
                        if (window.__fcCredsInit) return;
                        window.__fcCredsInit = true;

                        // ── Zoom control ────────────────────────────────────────
                        const applySize = (size) => {
                            document.querySelectorAll('[data-fc-creds-container]').forEach(el => el.setAttribute('data-cred-size', size));
                            document.querySelectorAll('.fc-cred-size-btn').forEach(btn => {
                                const active = btn.getAttribute('data-fc-cred-size') === size;
                                btn.classList.toggle('bg-white', active);
                                btn.classList.toggle('shadow-sm', active);
                                btn.classList.toggle('text-gray-950', active);
                                btn.classList.toggle('dark:bg-white/10', active);
                                btn.classList.toggle('dark:text-white', active);
                            });
                        };
                        const savedSize = localStorage.getItem('fc-cred-size') || 'md';
                        applySize(savedSize);
                        document.addEventListener('click', (ev) => {
                            const btn = ev.target.closest('.fc-cred-size-btn');
                            if (!btn) return;
                            const size = btn.getAttribute('data-fc-cred-size');
                            localStorage.setItem('fc-cred-size', size);
                            applySize(size);
                        });

                        // ── Speak character by character ────────────────────────
                        // Special chars get an English name so the browser's TTS
                        // reads them clearly; letters are spoken as "capital P" /
                        // "small p" so O vs o and I vs l is unambiguous over speaker.
                        const nameOf = {
                            '!':'exclamation','@':'at','#':'hash','$':'dollar','%':'percent',
                            '^':'caret','&':'ampersand','*':'star','(':'open paren',')':'close paren',
                            '-':'dash','_':'underscore','+':'plus','=':'equals',
                            '[':'open bracket',']':'close bracket','{':'open brace','}':'close brace',
                            ';':'semicolon',':':'colon',"'":'apostrophe','"':'quote',
                            ',':'comma','.':'dot','/':'slash','?':'question','\\':'backslash',
                            '|':'pipe','~':'tilde','`':'backtick',' ':'space',
                        };
                        const utterFor = (ch) => {
                            if (/[0-9]/.test(ch))   return ch;
                            if (/[A-Z]/.test(ch))   return 'capital ' + ch;
                            if (/[a-z]/.test(ch))   return 'small ' + ch;
                            return nameOf[ch] || ch;
                        };

                        document.addEventListener('click', (ev) => {
                            const btn = ev.target.closest('.fc-cred-speak');
                            if (!btn) return;
                            if (!('speechSynthesis' in window)) {
                                alert('المتصفح لا يدعم قراءة النص. حدّث المتصفح أو استخدم Chrome/Edge.');
                                return;
                            }
                            const targetId = btn.getAttribute('data-fc-speak-for');
                            const cred = document.getElementById(targetId);
                            if (!cred) return;

                            speechSynthesis.cancel();
                            btn.classList.add('is-speaking');

                            const chars = Array.from(cred.querySelectorAll('.fc-cred-char')).map(el => el.textContent);
                            let i = 0;
                            const speakNext = () => {
                                if (i >= chars.length) {
                                    btn.classList.remove('is-speaking');
                                    return;
                                }
                                const u = new SpeechSynthesisUtterance(utterFor(chars[i]));
                                u.lang = 'en-US';
                                u.rate = 0.7;
                                u.onend = () => { i++; speakNext(); };
                                u.onerror = () => { btn.classList.remove('is-speaking'); };
                                speechSynthesis.speak(u);
                            };
                            speakNext();
                        });

                        // ── Big-screen mode ─────────────────────────────────────
                        // Puts the credential on a full-viewport dark overlay in
                        // huge type so the employee can prop the phone on the
                        // desk and read from across the room. Wake Lock keeps
                        // the screen from dimming while they type on the PS
                        // controller. Landscape lock is requested but silently
                        // ignored if the browser refuses (Safari/iOS).
                        //
                        // Built with createElement (NOT innerHTML with literal
                        // tags) because Livewire's root-element detector uses a
                        // naive HTML scan that sees `<div>` strings inside
                        // <script> and counts them as extra component roots.
                        let __fcWakeLock = null;
                        const overlay = document.createElement('div');
                        overlay.className = 'fc-bigscreen';
                        overlay.setAttribute('hidden', '');
                        const bigValue = document.createElement('div');
                        bigValue.className = 'fc-bigscreen-value';
                        const bigHint = document.createElement('div');
                        bigHint.className = 'fc-bigscreen-hint';
                        bigHint.textContent = 'اضغط في أي مكان للإغلاق';
                        overlay.appendChild(bigValue);
                        overlay.appendChild(bigHint);
                        document.body.appendChild(overlay);

                        const closeBigscreen = () => {
                            overlay.setAttribute('hidden', '');
                            if (document.fullscreenElement) {
                                document.exitFullscreen().catch(() => {});
                            }
                            if (__fcWakeLock) {
                                __fcWakeLock.release().catch(() => {});
                                __fcWakeLock = null;
                            }
                        };

                        overlay.addEventListener('click', closeBigscreen);
                        document.addEventListener('keydown', (ev) => {
                            if (ev.key === 'Escape' && ! overlay.hasAttribute('hidden')) {
                                closeBigscreen();
                            }
                        });

                        document.addEventListener('click', (ev) => {
                            const btn = ev.target.closest('.fc-cred-bigscreen');
                            if (! btn) return;
                            const cred = document.getElementById(btn.getAttribute('data-fc-bigscreen-for'));
                            if (! cred) return;

                            // Clone the credential's inner HTML so all the
                            // character classes carry through — the big view
                            // inherits the same disambiguation as the small
                            // one, just larger.
                            bigValue.innerHTML = cred.innerHTML;
                            overlay.removeAttribute('hidden');

                            if (overlay.requestFullscreen) {
                                overlay.requestFullscreen().catch(() => {});
                            }
                            if (screen.orientation && screen.orientation.lock) {
                                screen.orientation.lock('landscape').catch(() => {});
                            }
                            if ('wakeLock' in navigator) {
                                navigator.wakeLock.request('screen')
                                    .then(lock => { __fcWakeLock = lock; })
                                    .catch(() => {});
                            }
                        });
                    })();
                </script>

                {{-- Step 2: TOTP --}}
                {{-- Two update sources:
                     - 1s wire:poll ONLY while a code is on screen, so the
                       countdown number, progress bar, and code rollover
                       stay in sync with server time. Bounded to ≤30 hits
                       per code; there's no polling in the common no-code
                       state.
                     - Extra-code approval: pushed via TotpExtraAllowanceGranted
                       broadcast (see getListeners) — no idle polling. --}}
                <div class="space-y-4 rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:p-6"
                     @if ($totpCodePsn || $totpCodeEa)
                         wire:poll.1s="tickTotp"
                     @endif
                >
                    <div class="flex items-center gap-3">
                        <span class="fc-step-num bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">٢</span>
                        <h3 class="text-lg font-bold text-gray-950 dark:text-white">كود التحقق (2FA)</h3>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">مسموح بـ {{ $assignment->psnTotpAllowance() }} كود PSN و{{ $assignment->eaTotpAllowance() }} كود EA لهذا التفعيل. بعدها يحتاج طلبك موافقة المشرف.</p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- PSN --}}
                        <div class="space-y-3 rounded-xl bg-blue-50/50 p-4 ring-1 ring-blue-200 dark:bg-blue-500/5 dark:ring-blue-500/20">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-blue-700 dark:text-blue-300">كود PSN</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $assignment->psn_totp_generations }}/{{ $assignment->psnTotpAllowance() }}</span>
                            </div>

                            @if ($totpCodePsn)
                                <p class="select-all text-center font-mono text-3xl font-bold tracking-widest text-blue-900 dark:text-blue-100">{{ $totpCodePsn }}</p>
                                <p class="text-center text-xs text-gray-500 dark:text-gray-400">صالح لمدة <span data-totp-countdown>{{ $totpSecondsLeft }}</span> ث</p>
                                <div class="h-1.5 overflow-hidden rounded-full bg-blue-200/70 dark:bg-blue-500/20">
                                    <div class="h-full bg-blue-500 transition-[width] duration-1000 ease-linear dark:bg-blue-400" data-totp-progress style="width: {{ min(100, max(0, (int) round(($totpSecondsLeft / 30) * 100))) }}%"></div>
                                </div>
                            @elseif ($this->hasPendingTotpApproval('psn'))
                                <p class="text-center text-xs font-semibold text-amber-600 dark:text-amber-400">بانتظار موافقة المشرف</p>
                            @elseif (! $assignment->canGeneratePsnTotp())
                                <p class="text-center text-xs font-semibold text-amber-600 dark:text-amber-400">وصلت للحد المسموح — اضغط الزر لطلب موافقة</p>
                            @else
                                <p class="text-center text-xs text-gray-500 dark:text-gray-400">اضغط للتوليد</p>
                            @endif

                            <div>{{ $this->generatePsnTotpAction }}</div>
                        </div>

                        {{-- EA --}}
                        <div class="space-y-3 rounded-xl bg-orange-50/50 p-4 ring-1 ring-orange-200 dark:bg-orange-500/5 dark:ring-orange-500/20">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-orange-700 dark:text-orange-300">كود EA</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $assignment->ea_totp_generations }}/{{ $assignment->eaTotpAllowance() }}</span>
                            </div>

                            @if ($totpCodeEa)
                                <p class="select-all text-center font-mono text-3xl font-bold tracking-widest text-orange-900 dark:text-orange-100">{{ $totpCodeEa }}</p>
                                <p class="text-center text-xs text-gray-500 dark:text-gray-400">صالح لمدة <span data-totp-countdown>{{ $totpSecondsLeft }}</span> ث</p>
                                <div class="h-1.5 overflow-hidden rounded-full bg-orange-200/70 dark:bg-orange-500/20">
                                    <div class="h-full bg-orange-500 transition-[width] duration-1000 ease-linear dark:bg-orange-400" data-totp-progress style="width: {{ min(100, max(0, (int) round(($totpSecondsLeft / 30) * 100))) }}%"></div>
                                </div>
                            @elseif ($this->hasPendingTotpApproval('ea'))
                                <p class="text-center text-xs font-semibold text-amber-600 dark:text-amber-400">بانتظار موافقة المشرف</p>
                            @elseif (! $assignment->canGenerateEaTotp())
                                <p class="text-center text-xs font-semibold text-amber-600 dark:text-amber-400">وصلت للحد المسموح — اضغط الزر لطلب موافقة</p>
                            @else
                                <p class="text-center text-xs text-gray-500 dark:text-gray-400">اضغط للتوليد</p>
                            @endif

                            <div>{{ $this->generateEaTotpAction }}</div>
                        </div>
                    </div>

                    {{-- No client-side ticker: the wire:poll.1s on the Step 2
                         container above re-renders totpSecondsLeft and the
                         progress bar width every second from the server, so
                         the display stays authoritative. Buzz-at-5s is
                         handled by watching data-totp-countdown mutations. --}}
                    @if ($totpCodePsn || $totpCodeEa)
                        <div x-data="{ buzzed: false }"
                             x-init="
                                const obs = new MutationObserver(() => {
                                    document.querySelectorAll('[data-totp-countdown]').forEach((el) => {
                                        if (parseInt(el.textContent) === 5 && ! buzzed && typeof navigator !== 'undefined' && navigator.vibrate) {
                                            buzzed = true;
                                            navigator.vibrate([120, 60, 120]);
                                        }
                                        if (parseInt(el.textContent) > 20) buzzed = false;
                                    });
                                });
                                document.querySelectorAll('[data-totp-countdown]').forEach((el) => obs.observe(el, { childList: true, characterData: true, subtree: true }));
                             "></div>
                    @endif
                </div>

                {{-- Step 3: Complete --}}
                <div class="space-y-4 rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:p-6">
                    <div class="flex items-center gap-3">
                        <span class="fc-step-num bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">٣</span>
                        <h3 class="text-lg font-bold text-gray-950 dark:text-white">إنهاء</h3>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">بعد اكتمال التفعيل على الكونسول، ارفع صورة إثبات وأرسل. المشرف سيراجعها.</p>
                    <div class="flex flex-wrap gap-3">
                        {{ $this->completeAction }}
                        {{ $this->wrongDataAction }}
                    </div>
                </div>

                {{-- Employees are on phones (the phone is BOTH the display AND
                     the camera). Filament's FileUpload is FilePond-based and
                     doesn't expose the HTML `capture` attribute — inject it
                     into the hidden browse input so tapping the upload button
                     opens the rear camera directly instead of the OS's
                     gallery/camera chooser. Runs on every action-modal open
                     because the input is created lazily inside the dialog. --}}
                <script>
                    (function () {
                        if (window.__fcCaptureInit) return;
                        window.__fcCaptureInit = true;

                        const applyCapture = () => {
                            document.querySelectorAll('.filepond--browser').forEach(el => {
                                if (! el.hasAttribute('capture')) {
                                    el.setAttribute('capture', 'environment');
                                }
                            });
                        };

                        // FilePond mounts asynchronously inside the Filament
                        // action modal, so waiting for a single tick isn't
                        // enough — observe the DOM for `.filepond--browser`
                        // appearing at any point, then patch it once.
                        new MutationObserver(applyCapture).observe(document.body, {
                            childList: true,
                            subtree: true,
                        });
                        applyCapture();
                    })();
                </script>
            </div>

            {{-- Side column: account summary, sticky on large screens.
                 Hidden on mobile — the compact strip at the top of the
                 page covers the same info without the scroll penalty. --}}
            <div class="hidden lg:sticky lg:top-6 lg:block">
                <div class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="h-2 w-full bg-gradient-to-r from-blue-500 via-blue-600 to-orange-500"></div>
                    <div class="space-y-4 p-6">
                        <div class="flex gap-1.5">
                            <span class="inline-flex items-center rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-300 dark:ring-blue-400/30">PSN</span>
                            <span class="inline-flex items-center rounded-lg bg-orange-50 px-2.5 py-1 text-xs font-bold text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-400/10 dark:text-orange-300 dark:ring-orange-400/30">EA</span>
                        </div>

                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">حساب رقم</p>
                            <p class="font-mono text-2xl font-bold text-gray-950 dark:text-white">#{{ $assignment->account->id }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3 border-t border-gray-100 pt-4 dark:border-white/5">
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">الحالة</p>
                                <p class="mt-0.5 text-sm font-bold text-gray-950 dark:text-white">
                                    @switch($assignment->status)
                                        @case(\App\Models\AccountAssignment::STATUS_PENDING) بانتظارك @break
                                        @case(\App\Models\AccountAssignment::STATUS_IN_PROGRESS) قيد التنفيذ @break
                                        @case(\App\Models\AccountAssignment::STATUS_AWAITING_REVIEW) بانتظار المراجعة @break
                                        @case(\App\Models\AccountAssignment::STATUS_COMPLETED) مكتمل @break
                                        @case(\App\Models\AccountAssignment::STATUS_FAILED) فشل @break
                                        @default {{ $assignment->status }}
                                    @endswitch
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">تاريخ التكليف</p>
                                <p class="mt-0.5 text-sm font-bold text-gray-950 dark:text-white">{{ $assignment->assigned_at?->diffForHumans() }}</p>
                            </div>
                        </div>

                        <a href="{{ \App\Filament\App\Pages\MyAccounts::getUrl() }}"
                           class="flex items-center justify-center gap-1 rounded-xl border border-gray-200 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50 hover:text-gray-950 dark:border-white/10 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white">
                            <x-heroicon-m-arrow-right class="h-4 w-4" />
                            العودة إلى حساباتي
                        </a>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>
</x-filament-panels::page>
