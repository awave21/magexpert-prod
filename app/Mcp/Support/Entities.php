<?php

namespace App\Mcp\Support;

use App\Mcp\ToolException;
use App\Models\Category;
use App\Models\Event;
use App\Models\MedicalLibrary;
use App\Models\Partner;
use App\Models\Speaker;
use App\Support\EventTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Описание сущностей, которыми управляет MCP: поля (одно место и для схемы, и для проверки), поиск, вывод и удаление.
 *
 * Поля описаны как [json-схема, правило Laravel, описание]. Картинки и документы загружаются отдельно: set_image, set_library_file (для партнёра и материала — сразу при создании).
 */
class Entities
{
    public const KEYS = ['event', 'speaker', 'category', 'partner', 'library'];

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        return match ($key) {
            'event' => self::event(),
            'speaker' => self::speaker(),
            'category' => self::category(),
            'partner' => self::partner(),
            'library' => self::library(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(): array
    {
        $str = fn (int $max): array => ['type' => 'string', 'maxLength' => $max];
        $bool = ['type' => 'boolean'];

        return [
            'model' => Event::class,
            'singular' => 'мероприятие',
            'plural' => 'мероприятия',
            'search' => ['title', 'slug', 'topic', 'city'],
            'order' => ['start_date', 'desc'],
            'can_create' => true,
            'fields' => [
                'title' => [$str(255), 'required|string|max:255', 'Название'],
                'slug' => [$str(255), 'nullable|string|max:255', 'Адрес страницы латиницей; пусто — сделается из названия'],
                'event_type' => [['type' => 'string', 'enum' => ['webinar', 'workshop', 'course', 'seminar', 'conference', 'other']], 'required|in:webinar,workshop,course,seminar,conference,other', 'Тип: webinar — вебинар, workshop — мастер-класс, course — курс, seminar — семинар, conference — конференция, other — другое'],
                'format' => [['type' => ['string', 'null'], 'enum' => ['online', 'offline', 'hybrid', null]], 'nullable|in:online,offline,hybrid', 'Формат: online, offline или hybrid'],
                'is_on_demand' => [$bool, 'boolean', 'Без даты — смотреть в любое время (тогда start_date не нужна)'],
                'start_date' => [['type' => ['string', 'null'], 'pattern' => '^\d{4}-\d{2}-\d{2}$'], 'nullable|date_format:Y-m-d', 'Дата начала ГГГГ-ММ-ДД (московская); обязательна, если не is_on_demand'],
                'start_time' => [['type' => ['string', 'null'], 'pattern' => '^\d{2}:\d{2}$'], 'nullable|date_format:H:i', 'Время начала ЧЧ:ММ (московское)'],
                'end_date' => [['type' => ['string', 'null'], 'pattern' => '^\d{4}-\d{2}-\d{2}$'], 'nullable|date_format:Y-m-d', 'Дата окончания ГГГГ-ММ-ДД'],
                'end_time' => [['type' => ['string', 'null'], 'pattern' => '^\d{2}:\d{2}$'], 'nullable|date_format:H:i', 'Время окончания ЧЧ:ММ'],
                'short_description' => [['type' => ['string', 'null']], 'nullable|string', 'Краткое описание'],
                'full_description' => [['type' => ['string', 'null']], 'nullable|string', 'Полное описание (HTML)'],
                'topic' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|string|max:255', 'Тема'],
                'location' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|string|max:255', 'Место проведения'],
                'city' => [['type' => ['string', 'null'], 'maxLength' => 120], 'nullable|string|max:120', 'Город'],
                'tags' => [['type' => ['array', 'null'], 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 120]], 'nullable|array|max:10', 'Теги для рассылок; пусто — подставятся адрес и дата'],
                'is_paid' => [$bool, 'boolean', 'Платное мероприятие'],
                'price' => [['type' => ['number', 'null'], 'minimum' => 0], 'nullable|numeric|min:0', 'Цена, ₽; обязательна для платного'],
                'show_price' => [$bool, 'boolean', 'Показывать цену на сайте'],
                'registration_enabled' => [$bool, 'boolean', 'Открыта ли регистрация'],
                'max_quantity' => [['type' => ['integer', 'null'], 'minimum' => 0], 'nullable|integer|min:0', 'Число мест'],
                'external_url' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|url|max:255', 'Ссылка на внешний сайт (https://…)'],
                'kinescope_id' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|string|max:255', 'ID записи в Кинескопе'],
                'kinescope_playlist_id' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|string|max:255', 'ID плейлиста в Кинескопе'],
                'kinescope_type' => [['type' => ['string', 'null'], 'enum' => ['video', 'playlist', null]], 'nullable|in:video,playlist', 'video или playlist'],
                'is_live' => [$bool, 'boolean', 'Идёт прямой эфир'],
                'is_active' => [$bool, 'boolean', 'Опубликовано на сайте (для этого лучше publish_event / unpublish_event)'],
                'is_archived' => [$bool, 'boolean', 'В архиве'],
                'sort_order' => [['type' => 'integer', 'minimum' => 0], 'integer|min:0', 'Порядок в списках'],
                'categories' => [['type' => 'array', 'items' => ['type' => 'integer']], 'array', 'ID категорий (замещает прежние)'],
            ],
            'filters' => [
                'status' => [['type' => 'string', 'enum' => ['active', 'hidden', 'archived', 'upcoming']], 'active — опубликованные, hidden — скрытые, archived — архив, upcoming — будущие опубликованные'],
            ],
            'apply_filters' => function (Builder $q, array $args): void {
                match ($args['status'] ?? null) {
                    'active' => $q->where('is_active', true)->where('is_archived', false),
                    'hidden' => $q->where('is_active', false),
                    'archived' => $q->where('is_archived', true),
                    'upcoming' => $q->where('is_active', true)->where('is_archived', false)->whereDate('start_date', '>=', now()->toDateString()),
                    default => null,
                };
            },
            'with' => ['speakers', 'categories'],
            'present' => fn (Event $e): array => [
                'id' => $e->id,
                'title' => $e->title,
                'slug' => $e->slug,
                'event_type' => $e->event_type,
                'format' => $e->format,
                'is_on_demand' => (bool) $e->is_on_demand,
                'start_date' => $e->start_date ? substr((string) $e->start_date, 0, 10) : null,
                'start_time' => $e->start_time ? substr((string) $e->start_time, 0, 5) : null,
                'end_date' => $e->end_date ? substr((string) $e->end_date, 0, 10) : null,
                'end_time' => $e->end_time ? substr((string) $e->end_time, 0, 5) : null,
                'short_description' => $e->short_description,
                'full_description' => $e->full_description,
                'topic' => $e->topic,
                'location' => $e->location,
                'city' => $e->city,
                'tags' => $e->tags,
                'is_paid' => (bool) $e->is_paid,
                'price' => $e->price,
                'show_price' => (bool) $e->show_price,
                'registration_enabled' => (bool) $e->registration_enabled,
                'max_quantity' => $e->max_quantity,
                'external_url' => $e->external_url,
                'kinescope_id' => $e->kinescope_id,
                'kinescope_playlist_id' => $e->kinescope_playlist_id,
                'kinescope_type' => $e->kinescope_type,
                'is_live' => (bool) $e->is_live,
                'is_active' => (bool) $e->is_active,
                'is_archived' => (bool) $e->is_archived,
                'has_image' => (bool) $e->image,
                'categories' => $e->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
                'speakers' => $e->speakers->sortBy('pivot.sort_order')->values()->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->full_name,
                    'role' => $s->pivot->role,
                    'topic' => $s->pivot->topic,
                    'sort_order' => $s->pivot->sort_order,
                ])->all(),
                'admin_url' => route('admin.events.edit', $e),
            ],
            'validate_extra' => function (array $merged, ?Model $existing): void {
                $errors = [];
                $onDemand = (bool) ($merged['is_on_demand'] ?? false);

                if (! $onDemand && empty($merged['start_date'])) {
                    $errors['start_date'][] = 'Укажите дату начала или отметьте is_on_demand';
                }
                if (! empty($merged['is_paid']) && ($merged['price'] ?? null) === null) {
                    $errors['price'][] = 'Для платного мероприятия укажите цену';
                }
                if (! empty($merged['end_date']) && ! empty($merged['start_date']) && $merged['end_date'] < $merged['start_date']) {
                    $errors['end_date'][] = 'Дата окончания не может быть раньше даты начала';
                }
                foreach ((array) ($merged['tags'] ?? []) as $tag) {
                    if (! preg_match(EventTags::PATTERN, (string) $tag)) {
                        $errors['tags'][] = "Недопустимый тег «{$tag}»";
                    }
                }
                if ($errors !== []) {
                    throw new ToolException('Проверьте поля мероприятия', $errors);
                }
            },
            'before_create' => function (array &$data): void {
                $data['slug'] = empty($data['slug']) ? self::uniqueSlug(Event::class, 'slug', $data['title']) : $data['slug'];
                $data['is_active'] ??= false;
                if (empty($data['tags'])) {
                    $data['tags'] = (new Event(['slug' => $data['slug'], 'start_date' => $data['start_date'] ?? null]))->defaultTags();
                }
            },
            'after_save' => function (Event $e, array $args): void {
                if (array_key_exists('categories', $args)) {
                    $e->categories()->sync($args['categories']);
                } elseif ($e->wasRecentlyCreated) {
                    $e->categories()->sync([]);
                }
            },
            'unique' => ['slug'],
            'delete_guard' => null,
            'on_delete' => function (Event $e): void {
                foreach ([$e->image, $e->file_path] as $path) {
                    if ($path && ! str_starts_with($path, 'http')) {
                        Storage::disk('public')->delete(str_replace(['/storage/', 'storage/'], '', $path));
                    }
                }
                Storage::disk('public')->deleteDirectory("events/{$e->id}/content");
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function speaker(): array
    {
        return [
            'model' => Speaker::class,
            'singular' => 'спикер',
            'plural' => 'спикеры',
            'search' => ['first_name', 'last_name', 'middle_name', 'company', 'position'],
            'order' => ['last_name', 'asc'],
            'can_create' => true,
            'fields' => [
                'last_name' => [['type' => 'string', 'maxLength' => 255], 'required|string|max:255', 'Фамилия'],
                'first_name' => [['type' => 'string', 'maxLength' => 255], 'required|string|max:255', 'Имя'],
                'middle_name' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|string|max:255', 'Отчество'],
                'position' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|string|max:255', 'Должность'],
                'company' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|string|max:255', 'Место работы'],
                'regalia' => [['type' => ['string', 'null']], 'nullable|string', 'Регалии'],
                'description' => [['type' => ['string', 'null']], 'nullable|string', 'О спикере'],
                'is_active' => [['type' => 'boolean'], 'boolean', 'Показывать на сайте'],
                'sort_order' => [['type' => 'integer', 'minimum' => 0], 'integer|min:0', 'Порядок'],
            ],
            'filters' => [],
            'with' => [],
            'present' => fn (Speaker $s): array => [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'last_name' => $s->last_name,
                'first_name' => $s->first_name,
                'middle_name' => $s->middle_name,
                'position' => $s->position,
                'company' => $s->company,
                'regalia' => $s->regalia,
                'description' => $s->description,
                'is_active' => (bool) $s->is_active,
                'sort_order' => $s->sort_order,
                'has_photo' => (bool) $s->photo,
                'events_count' => $s->events()->count(),
            ],
            'on_delete' => function (Speaker $s): void {
                if ($s->photo && ! str_starts_with($s->photo, 'http')) {
                    Storage::disk('public')->delete(str_replace('/storage/', '', $s->photo));
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function category(): array
    {
        return [
            'model' => Category::class,
            'singular' => 'категория',
            'plural' => 'категории',
            'search' => ['name', 'slug'],
            'order' => ['sort_order', 'asc'],
            'can_create' => true,
            'fields' => [
                'name' => [['type' => 'string', 'maxLength' => 255], 'required|string|max:255', 'Название'],
                'slug' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|string|max:255', 'Адрес; пусто — из названия'],
                'description' => [['type' => ['string', 'null']], 'nullable|string', 'Описание'],
                'is_active' => [['type' => 'boolean'], 'boolean', 'Показывать'],
                'sort_order' => [['type' => 'integer', 'minimum' => 0], 'integer|min:0', 'Порядок'],
            ],
            'filters' => [],
            'with' => [],
            'present' => fn (Category $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'is_active' => (bool) $c->is_active,
                'sort_order' => $c->sort_order,
                'events_count' => $c->events()->count(),
            ],
            'before_create' => function (array &$data): void {
                $data['slug'] = empty($data['slug']) ? self::uniqueSlug(Category::class, 'slug', $data['name']) : $data['slug'];
            },
            'unique' => ['slug'],
            'delete_guard' => fn (Category $c): ?string => $c->events()->exists() ? 'Нельзя удалить категорию, к которой привязаны мероприятия' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function partner(): array
    {
        return [
            'model' => Partner::class,
            'singular' => 'партнёр',
            'plural' => 'партнёры',
            'search' => ['name', 'website_url'],
            'order' => ['name', 'asc'],
            'can_create' => true,
            'media_create' => [
                'logo' => ['target' => 'partner_logo', 'required' => true, 'label' => 'Логотип'],
            ],
            'before_create' => function (array &$data): void {
                $data['logo_path'] = '';
            },
            'fields' => [
                'name' => [['type' => 'string', 'maxLength' => 255], 'required|string|max:255', 'Название'],
                'description' => [['type' => ['string', 'null'], 'maxLength' => 1000], 'nullable|string|max:1000', 'Описание'],
                'website_url' => [['type' => ['string', 'null'], 'maxLength' => 255], 'nullable|url|max:255', 'Сайт (https://…)'],
            ],
            'filters' => [],
            'with' => [],
            'present' => fn (Partner $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'website_url' => $p->website_url,
                'has_logo' => (bool) $p->logo_path,
            ],
            'on_delete' => function (Partner $p): void {
                Storage::disk('public')->deleteDirectory("partners/{$p->id}");
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function library(): array
    {
        return [
            'model' => MedicalLibrary::class,
            'singular' => 'материал библиотеки',
            'plural' => 'материалы библиотеки',
            'search' => ['title', 'description'],
            'order' => ['publication_date', 'desc'],
            'can_create' => true,
            'media_create' => [
                'file' => ['target' => 'library_file', 'required' => true, 'label' => 'Документ'],
                'cover' => ['target' => 'library_cover', 'required' => false, 'label' => 'Обложка'],
            ],
            'before_create' => function (array &$data): void {
                $data['file_path'] = 'placeholder';
            },
            'fields' => [
                'title' => [['type' => 'string', 'maxLength' => 255], 'required|string|max:255', 'Название'],
                'description' => [['type' => ['string', 'null']], 'nullable|string', 'Описание'],
                'publication_date' => [['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'], 'required|date_format:Y-m-d', 'Дата публикации ГГГГ-ММ-ДД'],
                'language' => [['type' => 'string', 'maxLength' => 10], 'required|string|max:10', 'Язык, например ru'],
            ],
            'filters' => [],
            'with' => [],
            'present' => fn (MedicalLibrary $m): array => [
                'id' => $m->id,
                'title' => $m->title,
                'description' => $m->description,
                'publication_date' => $m->publication_date ? substr((string) $m->publication_date, 0, 10) : null,
                'language' => $m->language,
                'has_file' => (bool) $m->file_path,
                'has_cover' => (bool) $m->image_path,
            ],
            'on_delete' => function (MedicalLibrary $m): void {
                Storage::disk('public')->deleteDirectory("medical-library/{$m->id}");
            },
        ];
    }

    public static function uniqueSlug(string $model, string $column, string $source, ?int $exceptId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $n = 2;

        while ($model::query()->where($column, $slug)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
