<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AccountAssignment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Employees may keep up to MAX_CONCURRENT_ACTIVATIONS started accounts
 * open at once and switch between them from "حساباتي". Once they hit
 * the cap, opening a NEW activation is blocked — this middleware then
 * bounces those requests to one of the already-started activations
 * until it's finished or failed. Non-activation pages are always let
 * through so the employee can navigate between their open accounts.
 */
class EnforceSingleActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user === null || ! $user->isEmployee() || $request->routeIs('logout')) {
            return $next($request);
        }

        $currentAssignmentId = null;
        if ($request->routeIs('filament.app.pages.activation')) {
            $routeAssignment = $request->route('assignment');
            $currentAssignmentId = $routeAssignment instanceof AccountAssignment
                ? $routeAssignment->id
                : (int) $routeAssignment;
        }

        $locked = AccountAssignment::lockedFor($user->id, $currentAssignmentId);
        if ($locked === null) {
            return $next($request);
        }

        return redirect()->to(
            \App\Filament\App\Pages\Activation::getUrl([
                'tenant'     => $locked->tenant->slug,
                'assignment' => $locked->id,
            ])
        );
    }
}
