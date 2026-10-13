<?php

namespace App\Sender\Services;

use App\Sender\Contracts\SubscriberSource;
use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Models\Organization;
use Illuminate\Support\Str;

/**
 * Синхронизируемые базы: подписчики берутся из приложения (источники помечены тегом sender.subscriber-sources).
 * База повторяет источник: новые адреса добавляются, данные обновляются, пропавшие из источника удаляются.
 */
class SubscriberSources
{
    public const TAG = 'sender.subscriber-sources';

    /**
     * Источники, доступные организации.
     *
     * @return array<string, SubscriberSource>
     */
    public function available(Organization $organization): array
    {
        $sources = [];

        foreach (app()->tagged(self::TAG) as $source) {
            /** @var SubscriberSource $source */
            if ($source->organization() === $organization->slug) {
                $sources[$source->key()] = $source;
            }
        }

        return $sources;
    }

    /**
     * @return array{added: int, updated: int, removed: int, total: int}
     */
    public function sync(ContactList $list): array
    {
        $list->loadMissing('organization');
        $source = $this->available($list->organization)[$list->source] ?? null;

        if ($source === null) {
            return ['added' => 0, 'updated' => 0, 'removed' => 0, 'total' => $list->contacts()->count()];
        }

        $existing = $list->contacts()->pluck('id', 'email');
        $seen = [];
        $rows = [];
        $now = now();

        foreach ($source->subscribers() as $subscriber) {
            $email = Str::lower(trim($subscriber['email']));

            if ($email === '' || isset($seen[$email]) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $seen[$email] = true;
            $rows[] = [
                'organization_id' => $list->organization_id,
                'list_id' => $list->id,
                'email' => $email,
                'name' => filled($subscriber['name']) ? trim((string) $subscriber['name']) : null,
                'data' => json_encode(array_filter($subscriber['data'], 'filled'), JSON_UNESCAPED_UNICODE),
                'tags' => ($tags = Contact::normalizeTags($subscriber['tags'] ?? [])) === [] ? null : json_encode($tags),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 500) {
                $this->upsert($rows);
                $rows = [];
            }
        }

        $this->upsert($rows);

        $gone = $existing->diffKeys($seen)->values();
        foreach ($gone->chunk(1000) as $ids) {
            Contact::query()->whereIn('id', $ids->all())->delete();
        }

        $list->forceFill(['synced_at' => $now])->save();

        // новые адреса проверяются так же, как загруженные из файла
        if (count($seen) > $existing->count() - $gone->count()) {
            CheckContactsJob::dispatch($list->id)->onQueue(config('sender.queue'));
        }

        return [
            'added' => count(array_diff_key($seen, $existing->all())),
            'updated' => $existing->count() - $gone->count(),
            'removed' => $gone->count(),
            'total' => count($seen),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        if ($rows !== []) {
            Contact::query()->upsert($rows, ['list_id', 'email'], ['name', 'data', 'tags', 'updated_at']);
        }
    }
}
