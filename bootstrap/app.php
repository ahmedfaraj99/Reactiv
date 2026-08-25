<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            '2fa.enrolled' => \App\Http\Middleware\EnsureTwoFactorEnrolled::class,
            '2fa'          => \App\Http\Middleware\RequireTwoFactorVerified::class,
            'device.fp'    => \App\Http\Middleware\BindDeviceFingerprint::class,
        ]);

        // The device fingerprint cookie is set client-side by JS (plain
        // text), so it must be excluded from Laravel's cookie encryption —
        // otherwise the decrypt fails silently and the middleware never
        // sees a value, and no device ever gets bound.
        $middleware->encryptCookies(except: ['fc_fp']);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
