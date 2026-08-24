<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AccountAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProofController extends Controller
{
    public function show(Request $request, AccountAssignment $assignment): StreamedResponse
    {
        /** @var ?User $user */
        $user = $request->user();

        abort_if($user === null || $assignment->proof_path === null, 404);
        abort_unless($this->canView($user, $assignment), 403);

        // Row can outlive the file (manual cleanup, restore from a
        // partial backup, seeded row with no bytes). Return 404 instead
        // of letting Flysystem throw a 500 from ->response().
        abort_unless(Storage::disk('local')->exists($assignment->proof_path), 404);

        return Storage::disk('local')->response($assignment->proof_path);
    }

    private function canView(User $user, AccountAssignment $assignment): bool
    {
        if ($assignment->tenant_id !== $user->tenant_id) {
            return false;
        }

        if ($user->isTenantOwner()) {
            return true;
        }

        if ($assignment->employee_id === $user->id) {
            return true;
        }

        $employee = $assignment->employee;
        if ($employee === null) {
            return false;
        }

        if ($user->isManager()) {
            $managedOfficeIds = $user->managedOffices()->pluck('id');
            return $managedOfficeIds->contains($employee->office_id);
        }

        if ($user->isSupervisor()) {
            return $employee->office_id === $user->office_id;
        }

        return false;
    }
}
