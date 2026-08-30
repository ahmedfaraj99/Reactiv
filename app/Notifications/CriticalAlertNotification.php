<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Enums\AlertType;

/**
 * Real-time delivery for the alerts that actually need eyes on them
 * quickly — without this, an alert is just a row in a table nobody is
 * watching.
 *
 * Queued: requires a running `php artisan queue:work` process — without
 * one, this just sits in the jobs table forever and nothing gets sent.
 *
 * The in-app bell (`database`) always fires. Mail is reserved for a
 * short allowlist of genuinely security-critical types — see MAIL_TYPES.
 */
class CriticalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Types that justify paging the owner by email. Anything else stays
     * in the bell only — otherwise the owner's inbox fills with routine
     * operational noise (TOTP requests, overdue chases, rate-limit trips,
     * off-hours notes) and the mail channel loses its signal value.
     */
    private const MAIL_TYPES = [
        AlertType::LoginAttack,
        AlertType::EmergencyFreeze,
        AlertType::SuspiciousSpeed,
        AlertType::DuplicateProof,
        AlertType::NewDevice,
    ];

    public function __construct(private readonly Alert $alert)
    {
    }

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return in_array($this->alert->type, self::MAIL_TYPES, true)
            ? ['mail', 'database']
            : ['database'];
    }

    /** @return array<string,mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'type'     => $this->alert->type,
            'severity' => $this->alert->severity,
            'message'  => $this->alert->message,
            'url'      => $this->alertsUrl($notifiable),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $typeLabel = $this->alert->type->label();
        $severityLabel = $this->alert->severity->label();

        $mail = (new MailMessage)
            ->subject($typeLabel.' — تنبيه '.$severityLabel.' (FC27AC)')
            ->greeting('تنبيه جديد يحتاج مراجعتك')
            ->line('النوع: '.$typeLabel)
            ->line('الخطورة: '.$severityLabel)
            ->line($this->alert->message ?? 'تنبيه بدون تفاصيل إضافية.');

        if ($url = $this->alertsUrl($notifiable)) {
            $mail->action('عرض التنبيهات', $url);
        }

        return $mail->line('لو لم يكن هذا يستحق المتابعة الآن، تقدر تُغلقه من صفحة التنبيهات.');
    }

    private function alertsUrl(object $notifiable): ?string
    {
        $slug = $notifiable->tenant?->slug;

        return $slug ? url('/app/t/'.$slug.'/alerts') : null;
    }
}
