{{-- Standalone "Add to Home Screen" support for phones. Employees are
     phone-only (see project_activation_workflow); with this, they get a
     home-screen icon that opens the app without browser chrome —
     effectively a lightweight native app without the app-store overhead.
     No service worker: offline caching isn't a requirement yet, and adding
     one without a matching cache strategy causes more bugs than it fixes. --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#4F46E5">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="FC27AC">
<link rel="apple-touch-icon" href="{{ asset('icon.svg') }}">
