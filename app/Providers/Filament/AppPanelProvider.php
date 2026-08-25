<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\App\Pages\DashboardPage;
use App\Http\Middleware\EnforceSingleActiveAccount;
use App\Http\Middleware\RequireOnlineApproval;
use App\Models\Tenant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->brandName('FC27AC')
            ->font('Tajawal')
            ->theme(asset('css/filament/app/theme.css'))
            ->colors([
                'primary' => Color::hex('#4F46E5'),
                'gray'    => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger'  => Color::Rose,
                'info'    => Color::Sky,
            ])
            ->favicon(asset('favicon.ico'))
            ->sidebarCollapsibleOnDesktop()
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->tenantRoutePrefix('t')
            ->profile(isSimple: true)
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                DashboardPage::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // 2FA temporarily disabled for development convenience.
                '2fa.enrolled',
                '2fa',
                'device.fp',
                EnforceSingleActiveAccount::class,
                RequireOnlineApproval::class,
            ])
            ->renderHook(
                \Filament\View\PanelsRenderHook::BODY_END,
                fn (): string => view('partials.device-fingerprint')->render(),
            )
            ->renderHook(
                // Load our app.js (Echo + Reverb bootstrap) into every
                // panel page so Livewire components can subscribe to
                // broadcast channels via $listeners. Rendered at BODY_END
                // after Livewire's own assets so `window.Echo` is ready
                // when component-init runs.
                \Filament\View\PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => (string) \Illuminate\Support\Facades\Blade::render("@vite('resources/js/app.js')"),
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn (): string => view('partials.pwa-head')->render(),
            )
            ->renderHook(
                // Persistent freeze banner — top of every page, every role,
                // whenever the owner has flipped the kill switch. Not
                // dismissable: the point is that nobody misses it.
                \Filament\View\PanelsRenderHook::CONTENT_START,
                fn (): string => view('partials.emergency-freeze-banner')->render(),
            );
    }
}
