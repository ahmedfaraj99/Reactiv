<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // In production, every generated URL and every redirect must use
        // https so the "secure" session cookie is actually sent. Left off
        // in local/testing so php artisan serve still works over http.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Restrict access to /pulse dashboard to tenant owners only.
        Gate::define('viewPulse', fn (User $user) => $user->isTenantOwner());
    }
}
