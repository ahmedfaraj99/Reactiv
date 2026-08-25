<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * First-time invitation for a freshly-created user. Carries a signed
 * activation URL (expires in 72 hours) — clicking it opens the
 * password-setting form. No plaintext token is stored on the user
 * record; the signature IS the token.
 */
class UserActivationInvitation extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'activation.show',
            now()->addHours(72),
            ['user' => $notifiable->getKey()],
        );

        return (new MailMessage)
            ->subject('دعوة لتفعيل حسابك في '.config('app.name'))
            ->greeting('مرحباً '.$notifiable->name)
            ->line('تم إنشاء حساب لك في نظام '.config('app.name').'. لتفعيل حسابك وتعيين كلمة المرور اضغط على الزر أدناه.')
            ->action('تفعيل الحساب وتعيين كلمة المرور', $url)
            ->line('الرابط صالح لمدة 72 ساعة. لو انتهت صلاحيته، اطلب من مديرك إرسال دعوة جديدة.')
            ->line('إذا لم تكن تنتظر هذه الدعوة، تجاهل هذا الإيميل — لن يتم تفعيل الحساب.')
            ->salutation('فريق '.config('app.name'));
    }
}
