<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AccountAssignment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Once an employee has pulled a 2FA code for an account, they're locked
 * to it — this middleware bounces any other page in the app panel back
 * to that account's activation screen until they complete or fail it.
 * Scoped to the panel's own page routes; it doesn't touch the shared
 * Livewire update endpoint, so the locked page itself keeps working.
 */
class EnforceSingleActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user === null || ! $user->isEmployee() || $request->routeIs('logout')) {
            return $next($request);
        }

        $locked = AccountAssignment::lockedFor($user->id);
        if ($locked === null) {
            return $next($request);
        }

        if ($request->routeIs('filament.app.pages.activation')) {
            $routeAssignment = $request->route('assignment');
            $assignmentId = $routeAssignment instanceof AccountAssignment
                ? $routeAssignment->id
                : (int) $routeAssignment;

            if ($assignmentId === $locked->id) {
                return $next($request);
            }
        }

        return redirect()->to(
            \App\Filament\App\Pages\Activation::getUrl([
                'tenant'     => $locked->tenant->slug,
                'assignment' => $locked->id,
            ])
        );
    }
}
