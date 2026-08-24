<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\SessionRequest;
use Filament\Pages\Page;

/**
 * The waiting-room for employees whose current login has not been
 * approved yet. Polls every 5 seconds; the moment a supervisor
 * approves them, the next poll redirects into the dashboard. The
 * middleware lets THIS page through unconditionally so the employee
 * doesn't hit an infinite redirect loop while unapproved.
 */
class AwaitingApproval extends Page
{
    protected static string $view = 'filament.app.pages.awaiting-approval';

    protected static ?string $slug = 'awaiting-approval';

    protected static ?string $title = 'بانتظار موافقة المشرف';

    protected static bool $shouldRegisterNavigation = false;

    public ?string $currentStatus = null;
    public ?string $lastRequestedAt = null;

    public static function canAccess(): bool
    {
        // Everyone who reaches this URL should be able to see it; the
        // middleware handles who *needs* to see it. Non-employees
        // landing here (via a stale link) get a harmless waiting screen.
        return auth()->check();
    }

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $u = auth()->user();
        if ($u === null) {
            return;
        }

        $latest = SessionRequest::query()
            ->where('user_id', $u->id)
            ->latest('id')
            ->first();

        $this->currentStatus   = $latest?->status;
        $this->lastRequestedAt = $latest?->requested_at?->diffForHumans();

        // Livewire morphs the DOM on wire:poll and does NOT execute
        // <script> tags inside the fragment, so the redirect must be
        // dispatched from PHP. The middleware then lets the next
        // request through and Filament lands us on the dashboard.
        if ($latest?->status === SessionRequest::STATUS_APPROVED) {
            $this->redirect('/app', navigate: false);
        }
    }
}
