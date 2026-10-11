<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\SenderChannel;
use App\Services\SenderMailService;
use Illuminate\Auth\Notifications\ResetPassword;

/**
 * Письмо со ссылкой для смены пароля. Отправляется через Sender, а не через почтовый драйвер Laravel.
 */
class ResetPasswordLink extends ResetPassword
{
    /**
     * @return list<class-string>
     */
    public function via(mixed $notifiable): array
    {
        return [SenderChannel::class];
    }

    public function toSender(User $notifiable, SenderMailService $mail): bool
    {
        return $mail->sendPasswordResetLink(
            $notifiable,
            $this->resetUrl($notifiable),
            (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
        );
    }
}
