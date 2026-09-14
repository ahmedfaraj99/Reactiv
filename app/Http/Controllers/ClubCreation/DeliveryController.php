<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClubCreation;

use App\Http\Controllers\Controller;
use App\Models\ClubCreation\Account;
use App\Models\ClubCreation\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function show(string $token)
    {
        $batch = Batch::where('token', $token)->firstOrFail();

        if ($batch->opened_at === null) {
            $batch->update(['opened_at' => now()]);
        }

        $accounts = $batch->accounts()->orderBy('id')->get();

        return response()
            ->view('club-creation.deliver', [
                'batch'    => $batch,
                'accounts' => $accounts,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function markDone(Request $request, string $token, int $accountId): JsonResponse
    {
        $batch = Batch::where('token', $token)->firstOrFail();

        $account = Account::where('id', $accountId)
            ->where('batch_id', $batch->id)
            ->firstOrFail();

        if ($account->status === Account::STATUS_ASSIGNED) {
            $account->update([
                'status'  => Account::STATUS_DONE,
                'done_at' => now(),
            ]);
        }

        return response()->json([
            'status'  => $account->status,
            'done_at' => $account->done_at?->format('Y-m-d H:i'),
        ]);
    }
}
