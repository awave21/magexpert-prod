<?php

namespace App\Sender\Transport;

use App\Sender\Models\Message;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

/**
 * Отправка через почтовый драйвер Laravel (MAIL_*): свой Postfix или внешнее SMTP-реле.
 */
class LaravelMailTransport implements Transport
{
    public function send(Message $message, array $content): void
    {
        Mail::send(
            [
                'html' => new HtmlString($content['html']),
                'text' => new HtmlString($content['text'] ?? strip_tags($content['html'])),
            ],
            [],
            function (MailMessage $mail) use ($message, $content): void {
                $mail->to($message->to_email)
                    ->from($message->from_email, $message->from_name)
                    ->subject($content['subject']);

                // свой Message-ID: по нему статус доставки находится в журнале Postfix
                $headers = $mail->getSymfonyMessage()->getHeaders();
                $headers->addIdHeader('Message-ID', $message->uuid.'@'.config('sender.mail_host'));
                // по этим заголовкам письмо находится в отчёте о жалобе (FBL Mail.ru и Яндекса); Feedback-ID нужен для FBL Яндекса и Gmail
                $headers->addTextHeader('X-Sender-Id', $message->uuid);
                $headers->addTextHeader('Feedback-ID', ($message->campaign_id ? 'campaign'.$message->campaign_id : 'service').':org'.$message->organization_id.':'.($message->campaign_id ? 'newsletter' : 'transactional').':sender');

                if ($message->reply_to) {
                    $mail->replyTo($message->reply_to);
                }

                $unsubscribe = $message->campaign_id ? ($message->data['unsubscribe_url'] ?? null) : null;

                if (is_string($unsubscribe)) {
                    $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
                    $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                }
            },
        );
    }
}
