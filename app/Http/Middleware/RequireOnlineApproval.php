<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\App\Pages\AwaitingApproval;
use App\Models\SessionRequest;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for employees: block every App-panel request until a manager or
 * supervisor has approved the current login. Non-employees pass through
 * unconditionally — supervisors and managers must always reach the
 * approval dashboard to grant approvals in the first place.
 *
 * The waiting page itself is exempt so an unapproved employee can see
 * "بانتظار الموافقة" instead of an infinite redirect. Logout too, so a
 * blocked employee can leave.
 */
class RequireOnlineApproval
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('fc27ac.session_approval.enabled')) {
            return $next($request);
        }

        /** @var ?User $user */
        $user = $request->user();

        if ($user === null || ! $user->isEmployee()) {
            return $next($request);
        }

        // Whitelist the routes an unapproved employee must still reach.
        if ($this->isWhitelisted($request)) {
            return $next($request);
        }

        $approval = SessionRequest::currentFor($user->id);
        if ($approval !== null && $approval->isCurrentlyValid()) {
            return $next($request);
        }

        // Resolve through Filament so the URL carries the correct
        // tenant slug (the panel is tenant-scoped at /app/t/{slug}/…);
        // hard-coding /app/awaiting-approval 404s. This middleware runs
        // BEFORE IdentifyTenant, so Filament::getTenant() is null here —
        // we must pass the user's tenant explicitly.
        return redirect()->to(AwaitingApproval::getUrl(
            panel: 'app',
            tenant: $user->tenant,
        ));
    }

    protected function isWhitelisted(Request $request): bool
    {
        // Match by route name, not URL suffix — a substring match would
        // silently let through any future page/record whose slug happens
        // to end with "login", "logout", or "awaiting-approval". Route
        // names are stable and defined by us, so this is precise.
        return $request->routeIs(
            'filament.app.pages.awaiting-approval',
            'filament.*.auth.login',
            'filament.*.auth.logout',
            'login',
            'logout',
        );
    }
}
