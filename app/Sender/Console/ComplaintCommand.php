<?php

namespace App\Sender\Console;

use App\Sender\Contracts\FeedbackMailbox;
use App\Sender\Services\ComplaintService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Жалобы на спам: из ящика FBL (--fetch, по расписанию) или из одного письма в файле или на stdin.
 */
class ComplaintCommand extends Command
{
    protected $signature = 'sender:complaints {file? : письмо-отчёт (.eml); без файла и --fetch читается stdin} {--fetch : забрать непрочитанные отчёты из ящика FBL}';

    protected $description = 'Обработать жалобы «Это спам» из отчётов FBL Mail.ru и Яндекса';

    public function handle(ComplaintService $complaints): int
    {
        if ($this->option('fetch')) {
            return $this->fetch($complaints);
        }

        $file = $this->argument('file');
        $raw = $file !== null ? (string) @file_get_contents($file) : (string) stream_get_contents(STDIN);
        $message = $complaints->handle($raw);

        $message === null
            ? $this->warn('В отчёте не найдено письмо Sender')
            : $this->info("Жалоба на письмо {$message->uuid} ({$message->to_email}): адрес заблокирован");

        return self::SUCCESS;
    }

    private function fetch(ComplaintService $complaints): int
    {
        if (blank(config('sender.fbl.host'))) {
            $this->error('Ящик FBL не настроен: SENDER_FBL_HOST, SENDER_FBL_USERNAME, SENDER_FBL_PASSWORD');

            return self::FAILURE;
        }

        $mailbox = app(FeedbackMailbox::class);
        $found = 0;
        $reports = 0;

        try {
            foreach ($mailbox->unseen(200) as $uid => $raw) {
                $reports++;
                $found += (int) ($complaints->handle($raw) !== null);
                $mailbox->markSeen($uid);
            }
        } catch (Throwable $exception) {
            $this->error('Не удалось прочитать ящик FBL: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $mailbox->close();
        }

        $this->info("Отчётов: {$reports}, жалоб на наши письма: {$found}");

        return self::SUCCESS;
    }
}
