<?php

namespace App\Sender\Mcp\Support;

use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Models\Template;
use Illuminate\Support\Str;

/**
 * Фирменный стиль организации: отправители, цвета, шрифты и логотип. Отдельных настроек бренда в Sender нет,
 * поэтому оформление выводится из последних писем (сначала блочных) и помечается как inferred.
 */
class Brand
{
    /**
     * Сколько последних писем просматривать.
     */
    private const TEMPLATES = 20;

    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization): array
    {
        $templates = $organization->templates()->latest('updated_at')->limit(self::TEMPLATES)->get();
        $blockTemplates = $templates->filter(fn (Template $t): bool => $t->editor === Template::EDITOR_BLOCKS && is_array($t->design));

        $style = $this->fromDesigns($blockTemplates->pluck('design')->all());
        $htmlColors = $this->colorsFromHtml($templates->pluck('body_html')->all());
        $fonts = $this->fontsFromHtml($templates->pluck('body_html')->all());

        return [
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'sender' => $this->sender($organization),
            'style' => [
                'inferred' => true,
                'source' => $blockTemplates->isNotEmpty()
                    ? 'Самые частые настройки из последних писем визуального редактора ('.$blockTemplates->count().' шт.)'
                    : 'Блочных писем пока нет: показаны значения редактора по умолчанию',
                'settings' => $style['settings'],
                'logo' => $style['logo'],
                'colors_in_html' => $htmlColors,
                'fonts_in_html' => $fonts,
            ],
            'note' => 'Цвета, шрифты и логотип выведены из существующих писем и могут быть неточными.',
        ];
    }

    /**
     * @return array{default: array{email: string, name: string}|null, addresses: list<array<string, mixed>>, verified_domains: list<string>}
     */
    private function sender(Organization $organization): array
    {
        $verified = $organization->domains()->where('status', Domain::STATUS_VERIFIED)->orderBy('id')->get();
        $addresses = $organization->senderAddresses()->orderBy('id')->get();
        $confirmed = $addresses->first(fn ($a): bool => $a->confirmed_at !== null && $verified->contains('id', $a->domain_id));

        $default = match (true) {
            $confirmed !== null => ['email' => Str::lower($confirmed->email), 'name' => $confirmed->name ?: $organization->name],
            $verified->isNotEmpty() => ['email' => 'noreply@'.$verified->first()->domain, 'name' => $organization->name],
            default => null,
        };

        return [
            'default' => $default,
            'addresses' => $addresses->map(fn ($a): array => [
                'id' => $a->id,
                'email' => $a->email,
                'name' => $a->name,
                'confirmed' => $a->confirmed_at !== null,
            ])->values()->all(),
            'verified_domains' => $verified->pluck('domain')->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $designs
     * @return array{settings: array<string, mixed>, logo: array{src: string, alt: string, width: int}|null}
     */
    private function fromDesigns(array $designs): array
    {
        $votes = [];
        $logo = null;

        foreach ($designs as $design) {
            foreach ((array) ($design['settings'] ?? []) as $key => $value) {
                if (array_key_exists($key, BlockCompiler::DEFAULT_SETTINGS) && $key !== 'preheader' && is_scalar($value) && $value !== '') {
                    $votes[$key][(string) $value] = ($votes[$key][(string) $value] ?? 0) + 1;
                }
            }

            $first = $design['blocks'][0] ?? null;

            if ($logo === null && is_array($first) && ($first['type'] ?? null) === 'image' && filled($first['src'] ?? null)) {
                $logo = ['src' => (string) $first['src'], 'alt' => (string) ($first['alt'] ?? ''), 'width' => (int) ($first['width'] ?? 180)];
            }
        }

        $settings = BlockCompiler::DEFAULT_SETTINGS;
        unset($settings['preheader']);

        foreach ($votes as $key => $values) {
            arsort($values);
            $value = (string) array_key_first($values);
            $default = BlockCompiler::DEFAULT_SETTINGS[$key];
            $settings[$key] = is_int($default) ? (int) $value : (is_float($default) ? (float) $value : $value);
        }

        return ['settings' => $settings, 'logo' => $logo];
    }

    /**
     * Самые частые цвета в HTML писем.
     *
     * @param  list<string|null>  $htmls
     * @return list<array{color: string, count: int}>
     */
    private function colorsFromHtml(array $htmls): array
    {
        $counts = [];

        foreach ($htmls as $html) {
            preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', (string) $html, $matches);

            foreach ($matches[1] as $hex) {
                $hex = strlen($hex) === 3 ? preg_replace('/(.)/', '$1$1', $hex) : $hex;
                $key = '#'.Str::upper((string) $hex);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        arsort($counts);

        return collect($counts)->take(10)->map(fn (int $count, string $color): array => ['color' => $color, 'count' => $count])->values()->all();
    }

    /**
     * @param  list<string|null>  $htmls
     * @return list<string>
     */
    private function fontsFromHtml(array $htmls): array
    {
        $counts = [];

        foreach ($htmls as $html) {
            preg_match_all('/font-family:\s*([^;"]+)/i', html_entity_decode((string) $html), $matches);

            foreach ($matches[1] as $font) {
                $font = trim($font);
                $counts[$font] = ($counts[$font] ?? 0) + 1;
            }
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, 5);
    }
}
