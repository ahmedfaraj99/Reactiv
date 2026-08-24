<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Real-time delivery for the alerts that actually need eyes on them
 * quickly (suspicious login activity, employee blocked waiting on a
 * code approval, etc.) — without this, an alert is just a row in a
 * table nobody is watching.
 *
 * Queued: requires a running `php artisan queue:work` process — without
 * one, this just sits in the jobs table forever and nothing gets sent.
 *
 * Also delivered on the `database` channel so the tenant owner sees it
 * as a bell notification inside the panel immediately on next page load —
 * mail can be delayed or missed, the in-app bell can't.
 */
class CriticalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Alert $alert)
    {
    }

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /** @return array<string,mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'type'     => $this->alert->type,
            'severity' => $this->alert->severity,
            'message'  => $this->alert->message,
            'url'      => url('/app/t/'.($notifiable->tenant?->slug ?? '').'/alerts'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $severityLabel = match ($this->alert->severity) {
            'critical' => 'حرج',
            'high'     => 'مرتفع',
            'medium'   => 'متوسط',
            default    => 'منخفض',
        };

        return (new MailMessage)
            ->subject('تنبيه '.$severityLabel.' — FC27AC')
            ->greeting('تنبيه جديد يحتاج مراجعتك')
            ->line($this->alert->message ?? 'تنبيه بدون تفاصيل إضافية.')
            ->line('الخطورة: '.$severityLabel)
            ->action('عرض التنبيهات', url('/app/t/'.($notifiable->tenant?->slug ?? '').'/alerts'))
            ->line('لو لم يكن هذا يستحق المتابعة الآن، تقدر تُغلقه من صفحة التنبيهات.');
    }
}
