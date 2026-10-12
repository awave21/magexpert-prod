<?php

namespace App\Console\Commands;

use App\Jobs\SyncUserToSender;
use App\Models\User;
use App\Sender\Client\HttpContactSyncClient;
use App\Sender\Client\SenderApiException;
use App\Sender\Contracts\ContactSyncClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Первичная загрузка (и сверка) базы «Пользователи MagExpert» в Sender через открытый API.
 * Отправляет всех пользователей с адресом; без согласия на рассылку — как отписанных. Повторный запуск безопасен.
 */
class SenderSyncUsers extends Command
{
    protected $signature = 'sender:sync-users
                            {--chunk=500 : Сколько контактов в одном запросе (не больше 1000)}
                            {--dry-run : Только посчитать и показать пример данных, без запросов к Sender}';

    protected $description = 'Загрузить всех пользователей сайта в базу «Пользователи MagExpert» в Sender через API';

    public function handle(ContactSyncClient $client): int
    {
        $chunk = (int) $this->option('chunk');

        if ($chunk < 1 || $chunk > 1000) {
            $this->error('--chunk должен быть от 1 до 1000.');

            return self::FAILURE;
        }

        $total = $this->users()->count();
        $listName = (string) config('sender.client.users_list_name');

        if ($this->option('dry-run')) {
            return $this->dryRun($total, $listName, $chunk);
        }

        if (! $client instanceof HttpContactSyncClient || ! HttpContactSyncClient::configured()) {
            $this->error('Sender API не настроен: укажите SENDER_CLIENT_DRIVER=http, SENDER_API_URL и SENDER_API_KEY. База пользователей обновляется только через API Sender.');

            return self::FAILURE;
        }

        try {
            $listId = SyncUserToSender::resolveListId($client);
        } catch (SenderApiException $exception) {
            $this->error('Не удалось найти или создать базу «'.$listName.'»: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("База «{$listName}» (ID {$listId}), пользователей с адресом: {$total}");

        $totals = ['created' => 0, 'updated' => 0, 'subscribed' => 0, 'unsubscribed' => 0, 'pending' => 0];
        $invalid = [];
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        try {
            $this->users()->chunkById($chunk, function (Collection $users) use ($client, $listId, &$totals, &$invalid, $bar): void {
                $contacts = $users->filter(fn (User $user): bool => SyncUserToSender::syncable($user))
                    ->map(fn (User $user): array => SyncUserToSender::payload($user))
                    ->values()
                    ->all();

                if ($contacts !== []) {
                    $result = $client->upsertContacts($listId, $contacts);

                    foreach (array_keys($totals) as $key) {
                        $totals[$key] += $result[$key];
                    }

                    foreach ($result['invalid'] as $row) {
                        $invalid[] = [
                            'email' => (string) ($row['email'] ?? $contacts[$row['index'] ?? -1]['email'] ?? '—'),
                            'error' => (string) (collect($row['errors'] ?? [])->flatten()->first() ?? 'ошибка в данных'),
                        ];
                    }
                }

                $bar->advance($users->count());
            });
        } catch (SenderApiException $exception) {
            $bar->clear();
            $this->error('Sender вернул ошибку, загрузка остановлена: '.$exception->getMessage());
            $this->line('Уже отправленные пакеты сохранены, команду можно запустить ещё раз.');

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Добавлено', 'Обновлено', 'Подписаны', 'Отписаны', 'Ждут подтверждения', 'С ошибками'],
            [[$totals['created'], $totals['updated'], $totals['subscribed'], $totals['unsubscribed'], $totals['pending'], count($invalid)]],
        );

        if ($invalid !== []) {
            $this->warn('Не приняты Sender:');
            $this->table(['Адрес', 'Ошибка'], $invalid);
        }

        return self::SUCCESS;
    }

    private function dryRun(int $total, string $listName, int $chunk): int
    {
        $subscribed = $this->users()->where('newsletter_consent', true)->count();

        $this->info("Проверка без отправки. База «{$listName}»");
        $this->table(['Пользователей с адресом', 'С согласием на рассылку', 'Без согласия', 'Запросов к API'], [
            [$total, $subscribed, $total - $subscribed, (int) ceil($total / $chunk)],
        ]);

        $sample = $this->users()->first();

        if ($sample !== null) {
            $this->line('Пример контакта:');
            $this->line((string) json_encode(SyncUserToSender::payload($sample), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<User>
     */
    private function users(): Builder
    {
        return User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('email', 'not like', '%@mag-expert.invalid')
            ->orderBy('id');
    }
}
