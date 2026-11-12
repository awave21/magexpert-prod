<?php

namespace App\Sender\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

class PasswordResetMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public string $url)
    {
        $this->onQueue(config('sender.transactional_queue'));
        $this->afterCommit();
    }

    public function build(): self
    {
        $url = e($this->url);

        return $this->from((string) config('sender.client.system_from_address'), 'MagSender')
            ->subject('Восстановление пароля MagSender')
            ->html('<div lang="ru" style="font-family:Arial,sans-serif;line-height:1.6;max-width:560px;margin:32px auto"><h1>Восстановление пароля</h1><p>Вы запросили смену пароля в MagSender.</p><p><a href="'.$url.'">Задать новый пароль</a></p><p>Ссылка действует 60 минут и может быть использована один раз.</p><p>Если вы не запрашивали смену пароля, проигнорируйте это письмо. Ваш пароль останется прежним.</p><p>Это автоматическое письмо, отвечать на него не нужно.</p></div>');
    }
}
