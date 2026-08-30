<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Models\Alert;
use App\Models\Tenant;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Support\Enums\ActionSize;
use Filament\Widgets\Widget;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use App\Enums\AlertType;

/**
 * Owner-only kill switch. One glaring button that stops every
 * credential reveal and TOTP generation across the whole tenant when
 * something bad is unfolding (suspected leak, chargeback, a phone
 * turned up missing). Reversible with a single click once the
 * incident is handled — freezing and un-freezing both fire a critical
 * alert so the audit trail records who did what and when.
 */
class EmergencyFreezeWidget extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $view = 'filament.app.widgets.emergency-freeze-widget';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isTenantOwner() ?? false;
    }

    /**
     * A second owner (different device, another browser tab) flipping the
     * freeze state broadcasts on the tenant channel; this widget refreshes
     * so both buttons and the banner stay in sync without a manual reload.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $tenantId = (int) (auth()->user()?->tenant_id ?? 0);

        return [
            'echo-private:tenant.'.$tenantId.',.tenant.freeze' => '$refresh',
        ];
    }

    public function getTenant(): Tenant
    {
        return filament()->getTenant();
    }

    public function freezeAction(): Action
    {
        return Action::make('freeze')
            ->label('🚨 تجميد الطوارئ')
            ->color('danger')
            ->size(ActionSize::Large)
            ->visible(fn (): bool => ! $this->getTenant()->isFrozen())
            ->requiresConfirmation()
            ->modalHeading('تجميد كل العمليات الحساسة')
            ->modalDescription('سيتوقف كل الموظفين عن كشف بيانات الحسابات وتوليد الأكواد فوراً. استخدم عند الاشتباه بتسريب أو حادثة أمنية.')
            ->modalSubmitActionLabel('نعم، جمّد الآن')
            ->form([
                Textarea::make('reason')
                    ->label('سبب التجميد')
                    ->helperText('يُعرض على كل مستخدم في النظام مع البانر الأحمر.')
                    ->required()
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                $tenant = $this->getTenant();
                $u = auth()->user();

                $tenant->update([
                    'frozen_at'     => now(),
                    'frozen_reason' => $data['reason'],
                    'frozen_by'     => $u->id,
                ]);

                Alert::create([
                    'tenant_id' => $tenant->id,
                    'user_id'   => $u->id,
                    'type'      => AlertType::EmergencyFreeze,
                    'severity'  => 'critical',
                    'message'   => "فُعِّل تجميد الطوارئ — السبب: {$data['reason']}",
                    'payload'   => ['action' => 'frozen', 'reason' => $data['reason']],
                ]);

                \App\Events\TenantFreezeChanged::dispatch($tenant->id, true);

                Notification::make()
                    ->danger()
                    ->title('تم تجميد النظام')
                    ->body('كل العمليات الحساسة موقوفة حتى تفكّ التجميد.')
                    ->persistent()
                    ->send();
            });
    }

    public function unfreezeAction(): Action
    {
        return Action::make('unfreeze')
            ->label('فكّ التجميد')
            ->color('success')
            ->size(ActionSize::Large)
            ->visible(fn (): bool => $this->getTenant()->isFrozen())
            ->requiresConfirmation()
            ->modalHeading('استئناف العمليات')
            ->modalDescription('سيعود الموظفون للعمل مباشرة. تأكد من انتهاء الحادثة قبل التأكيد.')
            ->modalSubmitActionLabel('نعم، استأنف العمل')
            ->action(function (): void {
                $tenant = $this->getTenant();
                $u = auth()->user();

                $tenant->update([
                    'frozen_at'     => null,
                    'frozen_reason' => null,
                    'frozen_by'     => null,
                ]);

                Alert::create([
                    'tenant_id' => $tenant->id,
                    'user_id'   => $u->id,
                    'type'      => AlertType::EmergencyFreeze,
                    'severity'  => 'high',
                    'message'   => 'تم فكّ تجميد الطوارئ — النظام يعمل بشكل طبيعي',
                    'payload'   => ['action' => 'unfrozen'],
                ]);

                \App\Events\TenantFreezeChanged::dispatch($tenant->id, false);

                Notification::make()
                    ->success()
                    ->title('استؤنف العمل')
                    ->send();
            });
    }
}
