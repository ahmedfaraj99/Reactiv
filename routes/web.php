<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\Auth\TwoFactorVerifyController;
use App\Http\Controllers\ProofController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::redirect('/login', '/admin/login')->name('login');
Route::redirect('/app/login', '/admin/login');

// Deep health check for external monitors (UptimeRobot etc). Token-gated
// so it's not a free reconnaissance surface — the plain /up route stays
// public for basic reachability. Returns "fail" as a keyword the monitor
// can watch for; alert on any 5xx OR presence of "fail" in the body.
Route::get('/health/deep', function () {
    abort_unless(
        hash_equals((string) config('app.health_token'), (string) request()->query('token', '')),
        404,
    );

    $checks = [];

    try {
        DB::connection()->getPdo();
        $checks['db'] = 'ok';
    } catch (\Throwable $e) {
        $checks['db'] = 'fail';
    }

    try {
        Cache::put('_health_probe', '1', 5);
        $checks['cache'] = Cache::get('_health_probe') === '1' ? 'ok' : 'fail';
    } catch (\Throwable $e) {
        $checks['cache'] = 'fail';
    }

    $checks['storage'] = is_writable(storage_path('framework')) ? 'ok' : 'fail';

    $allOk = ! in_array('fail', $checks, true);

    return response()->json(['status' => $allOk ? 'ok' : 'fail', 'checks' => $checks], $allOk ? 200 : 503);
})->middleware('throttle:6,1');

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/2fa/setup', [TwoFactorSetupController::class, 'show'])->name('2fa.setup.show');
    Route::post('/2fa/setup', [TwoFactorSetupController::class, 'store'])->name('2fa.setup.store');

    Route::get('/2fa/verify', [TwoFactorVerifyController::class, 'show'])->name('2fa.verify.show');
    Route::post('/2fa/verify', [TwoFactorVerifyController::class, 'store'])->name('2fa.verify.store');

    Route::get('/app/proof/{assignment}', [ProofController::class, 'show'])->name('proof.show');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect('/admin/login');
    })->name('logout');
});
