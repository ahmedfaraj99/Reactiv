import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Reverb runs on the same host as Laravel (no separate broadcast provider).
// The client picks up REVERB_* build-time env from Vite. If Reverb isn't
// running the socket just fails to connect — no page functionality depends
// on it as a hard requirement, listeners are additive to the base UI.
// The private-channel auth endpoint is a Laravel POST and requires the
// CSRF token — Sanctum's XSRF-TOKEN cookie handling isn't in play here,
// so we pull the token from the standard meta tag Filament emits and
// forward it as a header on the auth request.
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true,
    auth: {
        headers: {
            'X-CSRF-TOKEN': csrfToken,
        },
    },
});
