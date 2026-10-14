<?php

namespace App\Sender\Mcp\Support;

use App\Sender\Mcp\ToolException;
use App\Sender\Models\Contact;
use App\Sender\Models\Organization;
use App\Sender\Services\BaseVariables;
use App\Sender\Services\CampaignService;
use App\Sender\Support\SenderUi;

/**
 * Переменные писем: какие использованы в тексте, какие известны организации и тестовые значения для них.
 */
class TemplateVariables
{
    /**
     * Служебные переменные, которые подставляет сам Sender.
     */
    public const SERVICE_KEYS = ['campaign_test', 'confirm_url', 'organization_name'];

    /**
     * Сколько подписчиков просматривать, чтобы собрать поля из баз.
     */
    private const CONTACT_SCAN = 2000;

    public function __construct(private readonly CampaignService $campaigns) {}

    /**
     * Имена переменных в {{ name }} и {{#if name}}, в порядке появления.
     *
     * @return list<string>
     */
    public function used(string ...$sources): array
    {
        $found = [];

        foreach ($sources as $source) {
            preg_match_all('/\{\{\s*(?:#if\s+)?([a-zA-Z0-9_.]+)\s*\}\}/', $source, $matches);

            foreach ($matches[1] as $key) {
                $found[$key] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Поля подписчиков из баз организации: ключ => пример значения.
     *
     * @return array<string, string>
     */
    public function contactFields(Organization $organization): array
    {
        $fields = [];

        $organization->contacts()->whereNotNull('data')->select(['id', 'data'])->orderByDesc('id')->limit(self::CONTACT_SCAN)
            ->get()->each(function (Contact $contact) use (&$fields): void {
                foreach ((array) $contact->data as $key => $value) {
                    if (is_string($key) && is_scalar($value) && (string) $value !== '' && ! isset($fields[$key])) {
                        $fields[$key] = (string) $value;
                    }
                }
            });

        return $fields;
    }

    /**
     * Все переменные, которые можно использовать в письмах организации.
     *
     * @return array<string, string> ключ => откуда (base, custom, contact, service)
     */
    public function known(Organization $organization): array
    {
        $known = array_fill_keys(BaseVariables::keys(), 'base');
        $known += array_fill_keys(self::SERVICE_KEYS, 'service');
        $known += array_fill_keys($organization->variables()->pluck('key')->all(), 'custom');
        $known += array_fill_keys(array_keys($this->contactFields($organization)), 'contact');

        return $known;
    }

    /**
     * Тестовые значения: примеры базовых переменных, значения переменных организации,
     * затем данные подписчика из базы (если указана) и, наконец, явно переданные данные.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sampleData(Organization $organization, array $data = [], ?int $listId = null): array
    {
        $sample = array_column(BaseVariables::all(), 'sample', 'key');
        $sample['unsubscribe_url'] = SenderUi::url('unsubscribe/example');
        $sample['speakers'] = [
            ['name' => 'Иван Иванов', 'regalia' => 'Д.м.н., профессор', 'position' => 'Заведующий кафедрой', 'company' => 'Клиника «Пример»', 'photo' => 'https://example.com/storage/speakers/example.webp'],
            ['name' => 'Пётр Петров', 'position' => 'Врач-гинеколог', 'company' => 'Клиника «Пример»'],
        ];
        $sample = array_merge(
            $sample,
            $organization->variables()->whereNotNull('default_value')->where('default_value', '!=', '')->pluck('default_value', 'key')->all(),
        );

        if ($listId !== null) {
            $list = $organization->lists()->find($listId);

            if ($list === null) {
                throw new ToolException("База с ID {$listId} не найдена в вашей организации");
            }

            $contact = $list->contacts()->deliverable()->orderBy('id')->first() ?? $list->contacts()->orderBy('id')->first();

            if ($contact !== null) {
                $sample = array_merge($sample, (array) ($contact->data ?? []), $this->campaigns->contactVariables($contact));
            }
        }

        return array_merge($sample, $data);
    }
}
