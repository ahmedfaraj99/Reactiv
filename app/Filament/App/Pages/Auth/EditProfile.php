<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Support\Facades\Auth;

class EditProfile extends BaseEditProfile
{
    public bool $passwordWasChanged = false;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->passwordWasChanged = filled($data['password'] ?? null);

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->passwordWasChanged) {
            return;
        }

        $guard = Filament::getCurrentPanel()?->getAuthGuard() ?? 'web';
        Auth::guard($guard)->logout();
        session()->invalidate();
        session()->regenerateToken();
    }

    protected function getRedirectUrl(): ?string
    {
        if ($this->passwordWasChanged) {
            return Filament::getCurrentPanel()?->getLoginUrl() ?? url('/app/login');
        }

        return parent::getRedirectUrl();
    }
}
