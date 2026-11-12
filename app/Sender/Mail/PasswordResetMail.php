<?php

namespace App\Sender\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\HtmlString;

class PasswordResetMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @param  array{subject: string, html: string, text: string|null}  $content
     */
    public function __construct(
        public string $url,
        public int $templateId,
        public array $content,
        public string $fromEmail,
        public ?string $fromName,
        public ?string $replyToAddress = null,
    ) {
        $this->onQueue(config('sender.transactional_queue'));
        $this->afterCommit();
    }

    public function build(): self
    {
        $mail = $this->from($this->fromEmail, $this->fromName)
            ->subject($this->content['subject'])
            ->html($this->content['html']);

        if ($this->content['text'] !== null) {
            $mail->text(new HtmlString($this->content['text']));
        }
        if ($this->replyToAddress !== null) {
            $mail->replyTo($this->replyToAddress);
        }

        return $mail;
    }
}
