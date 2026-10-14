<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;

/**
 * Старые платежи хранили в metadata сгенерированный пароль нового пользователя. Команда стирает его.
 * Новые платежи пароль не содержат.
 */
class ScrubStoredPasswords extends Command
{
    protected $signature = 'payments:scrub-passwords {--dry-run : Только посчитать, ничего не менять}';

    protected $description = 'Стереть сгенерированные пароли из данных платежей';

    public function handle(): int
    {
        $found = 0;

        Payment::query()->orderBy('id')->chunkById(500, function ($payments) use (&$found): void {
            foreach ($payments as $payment) {
                $metadata = $payment->metadata;

                if (! is_array($metadata) || ! array_key_exists('generated_password', $metadata)) {
                    continue;
                }

                $found++;

                if (! $this->option('dry-run')) {
                    unset($metadata['generated_password']);
                    $payment->forceFill(['metadata' => $metadata])->saveQuietly();
                }
            }
        });

        $this->info(($this->option('dry-run') ? 'Нашлось' : 'Очищено').": платежей с паролем {$found}");

        return self::SUCCESS;
    }
}
