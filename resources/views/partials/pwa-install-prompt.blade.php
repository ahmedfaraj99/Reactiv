{{-- Home-screen install nudge. Two flavors:
     - Chromium (Android/desktop Chrome/Edge): listens for the
       `beforeinstallprompt` event the browser fires when the site meets
       PWA install criteria, stashes it, and shows a small floating
       button that calls .prompt() on tap.
     - iOS Safari: never fires that event, so we detect iOS + Safari +
       not-already-standalone and show a one-shot hint pointing at the
       Share > "Add to Home Screen" flow.
     Both remember dismissal in localStorage so we don't nag on every
     page load. Hidden entirely when the page is already running as an
     installed app (display-mode: standalone). --}}
<template id="pwa-install-android-template">
    <div id="pwa-install-android"
         class="fixed bottom-4 inset-x-4 z-50 mx-auto max-w-md rounded-xl bg-primary-600 text-white shadow-2xl ring-1 ring-black/10 flex items-center gap-3 p-3">
        <svg class="h-6 w-6 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5m0 0l5-5m-5 5V4"/>
        </svg>
        <div class="flex-1 min-w-0 text-sm">
            <p class="font-bold">ثبّت التطبيق على شاشتك</p>
            <p class="text-xs opacity-90">يفتح بضغطة، بدون شريط المتصفح</p>
        </div>
        <button type="button" data-pwa-install
                class="rounded-md bg-white/20 hover:bg-white/30 px-3 py-1.5 text-sm font-bold">
            تثبيت
        </button>
        <button type="button" data-pwa-dismiss aria-label="إخفاء"
                class="rounded-md p-1.5 hover:bg-white/20">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
</template>

<template id="pwa-install-ios-template">
    <div id="pwa-install-ios"
         class="fixed bottom-4 inset-x-4 z-50 mx-auto max-w-md rounded-xl bg-slate-800 text-white shadow-2xl ring-1 ring-black/10 p-3">
        <div class="flex items-start gap-3">
            <svg class="h-6 w-6 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4"/>
            </svg>
            <div class="flex-1 min-w-0 text-sm">
                <p class="font-bold mb-1">أضف التطبيق لشاشتك الرئيسية</p>
                <p class="text-xs opacity-90 leading-relaxed">
                    اضغط زر المشاركة <span class="inline-block align-middle">⎋</span>
                    في الأسفل، ثم اختر «إضافة إلى الشاشة الرئيسية».
                </p>
            </div>
            <button type="button" data-pwa-dismiss aria-label="إخفاء"
                    class="rounded-md p-1.5 hover:bg-white/20 flex-shrink-0">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>
</template>

<script>
(function () {
    // Already-installed detection — both the standard media query and
    // the iOS-only navigator.standalone (Safari never sets the query).
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    if (isStandalone) return;

    const DISMISS_KEY = 'pwa_install_dismissed_at';
    // Two-week snooze after dismissal — long enough not to nag, short
    // enough that a device that switched contexts (new employee gets
    // the phone) still sees the offer eventually.
    const DISMISS_MS = 14 * 24 * 60 * 60 * 1000;
    const dismissedAt = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
    if (dismissedAt && (Date.now() - dismissedAt) < DISMISS_MS) return;

    const dismiss = (el) => {
        localStorage.setItem(DISMISS_KEY, String(Date.now()));
        el?.remove();
    };

    // Android / desktop Chromium path.
    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;

        const tpl = document.getElementById('pwa-install-android-template');
        if (! tpl) return;
        const node = tpl.content.firstElementChild.cloneNode(true);
        document.body.appendChild(node);

        node.querySelector('[data-pwa-install]').addEventListener('click', async () => {
            if (! deferredPrompt) return;
            deferredPrompt.prompt();
            try { await deferredPrompt.userChoice; } catch (_) {}
            deferredPrompt = null;
            dismiss(node);
        });
        node.querySelector('[data-pwa-dismiss]').addEventListener('click', () => dismiss(node));
    });

    // iOS Safari path — no beforeinstallprompt, so sniff and hint.
    const ua = window.navigator.userAgent;
    const isIOS = /iPad|iPhone|iPod/.test(ua) && ! window.MSStream;
    const isSafari = /^((?!chrome|android|crios|fxios|edgios).)*safari/i.test(ua);
    if (isIOS && isSafari) {
        // Wait for the DOM/Livewire to settle — throwing the banner in
        // mid-render fights with Livewire's morph and can flash.
        window.addEventListener('load', () => {
            const tpl = document.getElementById('pwa-install-ios-template');
            if (! tpl) return;
            const node = tpl.content.firstElementChild.cloneNode(true);
            document.body.appendChild(node);
            node.querySelector('[data-pwa-dismiss]').addEventListener('click', () => dismiss(node));
        });
    }
})();
</script>
