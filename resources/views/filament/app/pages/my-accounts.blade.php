<x-filament-panels::page>
    @php
        $locked = $this->getLockedAssignment();
        $stats = $this->todayStats();
        $undo = $this->getUndoFailContext();
        $pendingCount = $this->getPendingCount();
    @endphp

    {{-- New-assignment alert: the employee typically has the phone on
         the desk, not in hand, so a silent table refresh is easy to
         miss. This hidden marker re-renders each poll (Livewire's
         morph swaps the data-count in place); the JS observer diffs
         against sessionStorage and beeps + vibrates the phone the
         moment the number goes up. Permission is requested lazily on
         the first tap anywhere on the page so we never fire the
         browser's permission prompt without a user gesture. --}}
    <div id="pending-count-marker"
         data-pending-count="{{ $pendingCount }}"
         wire:poll.15s
         class="hidden"
         aria-hidden="true"></div>

    <script>
        (function () {
            const KEY = 'my_accounts_last_count';

            // Small tone via WebAudio — dodges the need to ship an audio
            // asset and works offline. Two short 880Hz beeps ≈ 350ms.
            const beep = () => {
                try {
                    const AC = window.AudioContext || window.webkitAudioContext;
                    if (! AC) return;
                    const ctx = new AC();
                    const play = (startAt) => {
                        const o = ctx.createOscillator();
                        const g = ctx.createGain();
                        o.type = 'sine';
                        o.frequency.value = 880;
                        g.gain.setValueAtTime(0.0001, ctx.currentTime + startAt);
                        g.gain.exponentialRampToValueAtTime(0.3, ctx.currentTime + startAt + 0.02);
                        g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + startAt + 0.15);
                        o.connect(g).connect(ctx.destination);
                        o.start(ctx.currentTime + startAt);
                        o.stop(ctx.currentTime + startAt + 0.16);
                    };
                    play(0);
                    play(0.2);
                    setTimeout(() => ctx.close(), 500);
                } catch (_) {}
            };

            const vibrate = () => {
                if (navigator.vibrate) navigator.vibrate([180, 80, 180]);
            };

            const notify = (count) => {
                if (! ('Notification' in window)) return;
                if (Notification.permission !== 'granted') return;
                try {
                    new Notification('حساب جديد مخصَّص لك', {
                        body: `عندك ${count} حساب في الطابور`,
                        tag:  'fc27ac-new-assignment',
                        renotify: true,
                    });
                } catch (_) {}
            };

            // Deferred permission request — must be inside a user
            // gesture handler on every browser we care about.
            document.addEventListener('click', function askOnce() {
                if ('Notification' in window && Notification.permission === 'default') {
                    Notification.requestPermission().catch(() => {});
                }
                document.removeEventListener('click', askOnce);
            }, { once: true });

            const marker = document.getElementById('pending-count-marker');
            if (! marker) return;

            const current = parseInt(marker.dataset.pendingCount || '0', 10);
            const stored  = parseInt(sessionStorage.getItem(KEY) || 'NaN', 10);

            // First visit this session: seed the baseline WITHOUT
            // beeping. Beeping the queue you loaded with is noise, not
            // signal — the point is to catch what arrives afterward.
            if (isNaN(stored)) {
                sessionStorage.setItem(KEY, String(current));
            }

            const check = () => {
                const now = parseInt(marker.dataset.pendingCount || '0', 10);
                const prev = parseInt(sessionStorage.getItem(KEY) || '0', 10);
                if (now > prev) {
                    beep();
                    vibrate();
                    notify(now);
                }
                sessionStorage.setItem(KEY, String(now));
            };

            new MutationObserver(check).observe(marker, {
                attributes: true,
                attributeFilter: ['data-pending-count'],
            });
        })();
    </script>

    {{-- 5-second undo shelf for a "wrong data" click the employee
         regrets — mistaken failure is the one action here that's fully
         reversible (nothing has been sent to the customer, no proof
         has changed hands). The countdown is JS-only for the visual;
         the server also re-checks the expiry so a stale tab can't
         un-fail after the window closes. --}}
    @if ($undo)
        <div id="undo-fail-bar" data-expires-at="{{ $undo['expires_at'] }}"
             class="mb-4 flex items-center gap-3 rounded-2xl bg-rose-600 p-4 text-white shadow-lg ring-1 ring-rose-800">
            <x-heroicon-o-arrow-uturn-left class="h-6 w-6 flex-shrink-0" />
            <div class="flex-1 min-w-0">
                <p class="text-sm font-bold">سُجِّل الفشل على حساب #{{ $undo['assignment_id'] }}</p>
                <p class="text-xs opacity-90">
                    تراجع خلال <span data-undo-countdown class="font-mono font-bold">5</span> ثوانٍ
                </p>
            </div>
            <button type="button" wire:click="undoFail" wire:loading.attr="disabled"
                    class="flex-shrink-0 rounded-xl bg-white/20 px-4 py-2 text-sm font-bold transition hover:bg-white/30">
                تراجع
            </button>
        </div>
        <script>
            (function () {
                const bar = document.getElementById('undo-fail-bar');
                if (! bar) return;
                const expiresAt = parseInt(bar.dataset.expiresAt, 10) * 1000;
                const cd = bar.querySelector('[data-undo-countdown]');
                const tick = () => {
                    const left = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
                    if (cd) cd.textContent = String(left);
                    if (left <= 0) {
                        bar.remove();
                        clearInterval(handle);
                    }
                };
                tick();
                const handle = setInterval(tick, 250);
            })();
        </script>
    @endif

    {{-- Today snapshot for the employee. Hidden when there's nothing yet
         to celebrate/reflect on — an empty "0 activations today" is
         demoralising to see the moment you open the app in the morning. --}}
    @if ($stats['completed_count'] > 0)
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs text-gray-500 dark:text-gray-400">اليوم</p>
                <p class="mt-1 text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $stats['completed_count'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">تفعيل مكتمل</p>
            </div>

            @if ($stats['avg_minutes'] !== null)
                <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs text-gray-500 dark:text-gray-400">متوسط وقتك</p>
                    <p class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">
                        {{ $stats['avg_minutes'] }}<span class="text-sm font-normal text-gray-500 dark:text-gray-400"> د</span>
                    </p>
                    @if ($stats['comparison_percent'] !== null && $stats['comparison_percent'] !== 0)
                        <p class="text-xs {{ $stats['comparison_percent'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                            @if ($stats['comparison_percent'] > 0)
                                أسرع من الفريق بـ {{ $stats['comparison_percent'] }}%
                            @else
                                أبطأ من الفريق بـ {{ abs($stats['comparison_percent']) }}%
                            @endif
                        </p>
                    @elseif ($stats['team_avg_minutes'] !== null)
                        <p class="text-xs text-gray-500 dark:text-gray-400">متوسط الفريق: {{ $stats['team_avg_minutes'] }} د</p>
                    @endif
                </div>
            @endif

            @if ($stats['currency_configured'])
                <div class="col-span-2 rounded-2xl bg-primary-50 p-4 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:ring-primary-500/30 sm:col-span-2">
                    <p class="text-xs text-primary-700 dark:text-primary-300">مستحقك اليوم</p>
                    <p class="mt-1 font-mono text-2xl font-bold text-primary-900 dark:text-primary-100">{{ $stats['earnings'] }}</p>
                    <p class="text-xs text-primary-700/70 dark:text-primary-300/70">{{ $stats['completed_count'] }} × قيمة التفعيل</p>
                </div>
            @endif
        </div>
    @endif

    @if ($locked)
        <div class="mb-4 flex items-center gap-3 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
            <x-heroicon-o-lock-closed class="h-6 w-6 flex-shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="flex-1">
                <p class="text-sm font-bold text-amber-900 dark:text-amber-300">أنت مقفل على حساب #{{ $locked->account_id }} حالياً</p>
                <p class="mt-0.5 text-sm text-amber-800 dark:text-amber-200">أكمله بإرسال إثبات الإنجاز أو تسجيل بيانات خطأ قبل فتح أي حساب آخر.</p>
            </div>
            <a href="{{ route('filament.app.pages.activation', ['tenant' => filament()->getTenant()->slug, 'assignment' => $locked->id]) }}"
               class="flex-shrink-0 rounded-xl bg-amber-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-amber-700">
                افتحه الآن
            </a>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
