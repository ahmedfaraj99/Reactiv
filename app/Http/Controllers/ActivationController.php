<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Handles the "click the link in your invitation email → set a
 * password → your account is active" flow. The signed URL that got
 * the visitor here IS the token — no per-user activation column is
 * stored. The 'signed' middleware is applied on the route, so an
 * expired/tampered link never reaches these methods.
 *
 * Idempotency: if the user already has a password set (via a previous
 * activation), we do not let a stale link overwrite it. That way if
 * someone re-uses an old invite by accident, it fails loudly rather
 * than silently resetting the account.
 */
class ActivationController extends Controller
{
    public function show(User $user)
    {
        if ($user->email_verified_at !== null) {
            return redirect()->to(filament()->getPanel('app')->getLoginUrl() ?? '/admin/login')
                ->with('status', 'الحساب مفعّل بالفعل. سجل الدخول بكلمة السر التي اخترتها.');
        }

        return view('activation.set-password', ['user' => $user]);
    }

    public function store(User $user, Request $request)
    {
        if ($user->email_verified_at !== null) {
            return redirect()->to(filament()->getPanel('app')->getLoginUrl() ?? '/admin/login')
                ->with('status', 'الحساب مفعّل بالفعل.');
        }

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.required'  => 'كلمة السر مطلوبة.',
            'password.confirmed' => 'التأكيد لا يطابق كلمة السر.',
        ]);

        $user->forceFill([
            'password'          => Hash::make($data['password']),
            'email_verified_at' => now(),
            'active'            => true,
        ])->save();

        return redirect()->to(filament()->getPanel('app')->getLoginUrl() ?? '/admin/login')
            ->with('status', 'تم تفعيل الحساب. يمكنك الآن تسجيل الدخول.');
    }
}
