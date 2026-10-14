<?php

namespace App\Sender\Console;

use App\Sender\Models\Message;
use Illuminate\Console\Command;

/**
 * Стирает из журнала уже отправленных писем пароли и одноразовые ссылки (sender.redact_keys).
 * Новые письма очищаются сами сразу после отправки; команда нужна один раз для старых записей.
 */
class ScrubSecretsCommand extends Command
{
    protected $signature = 'sender:scrub-secrets {--dry-run : Только посчитать, ничего не менять}';

    protected $description = 'Стереть пароли и одноразовые ссылки из данных отправленных писем';

    public function handle(): int
    {
        $keys = (array) config('sender.redact_keys', []);
        $checked = 0;
        $scrubbed = 0;

        Message::query()->select(['id', 'data', 'status'])->orderBy('id')->chunkById(500, function ($messages) use ($keys, &$checked, &$scrubbed): void {
            foreach ($messages as $message) {
                $checked++;
                $data = $message->data;

                // письма в очереди ещё не отправлены: их данные нужны для отправки
                if (! is_array($data) || in_array($message->status, [Message::STATUS_QUEUED, Message::STATUS_PENDING, Message::STATUS_SENDING], true)) {
                    continue;
                }

                if (array_intersect_key($data, array_flip($keys)) === []) {
                    continue;
                }

                $scrubbed++;

                if (! $this->option('dry-run')) {
                    Message::query()->whereKey($message->id)->update(['data' => json_encode(Message::redact($data), JSON_UNESCAPED_UNICODE)]);
                }
            }
        });

        $this->info(($this->option('dry-run') ? 'Нашлось' : 'Очищено').": {$scrubbed} из {$checked} писем");

        return self::SUCCESS;
    }
}
