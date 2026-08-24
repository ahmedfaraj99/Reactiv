<script>
// Set-once, best-effort device fingerprint cookie. Value is a SHA-256 of
// stable, non-PII browser attributes. Read by BindDeviceFingerprint on
// the server. Clearing cookies resets it → fires "new device" alert.
(function () {
    if (document.cookie.split('; ').some(c => c.startsWith('fc_fp='))) return;

    const parts = [
        navigator.userAgent || '',
        navigator.language || '',
        (navigator.languages || []).join(','),
        screen.width + 'x' + screen.height,
        screen.colorDepth || '',
        Intl.DateTimeFormat().resolvedOptions().timeZone || '',
        navigator.hardwareConcurrency || '',
        navigator.deviceMemory || '',
        (navigator.platform || ''),
    ];
    const raw = parts.join('|');

    async function sha256(s) {
        const buf = new TextEncoder().encode(s);
        const hash = await crypto.subtle.digest('SHA-256', buf);
        return Array.from(new Uint8Array(hash))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    sha256(raw).then(fp => {
        const oneYear = 60 * 60 * 24 * 365;
        document.cookie = `fc_fp=${fp}; path=/; max-age=${oneYear}; SameSite=Lax`;
    });
})();
</script>
