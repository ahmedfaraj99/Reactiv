<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Auth;

use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportRedirects\Redirector;

class EditProfile extends BaseEditProfile
{
    protected function afterSave(): void
    {
        $passwordChanged = $this->form->getState()['password'] ?? null;

        if ($passwordChanged !== null && $passwordChanged !== '') {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
        }
    }

    protected function getRedirectUrl(): ?string
    {
        $passwordChanged = $this->form->getState()['password'] ?? null;

        if ($passwordChanged !== null && $passwordChanged !== '') {
            return filament()->getLoginUrl();
        }

        return parent::getRedirectUrl();
    }
}
