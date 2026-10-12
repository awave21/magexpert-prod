<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Contracts\SubscriberSource;
use App\Sender\Http\Requests\Admin\ContactListRequest;
use App\Sender\Http\Requests\Admin\SegmentRequest;
use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Models\ContactList;
use App\Sender\Services\EmailChecker;
use App\Sender\Services\ListFields;
use App\Sender\Services\SubscriberSources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ContactListController extends Controller
{
    use ResolvesOrganization;

    public function __construct(private readonly SubscriberSources $sources) {}

    private const CHECKS = [
        EmailChecker::STATUS_OK, EmailChecker::STATUS_ROLE, EmailChecker::STATUS_TYPO,
        EmailChecker::STATUS_DISPOSABLE, EmailChecker::STATUS_NO_MX, EmailChecker::STATUS_INVALID,
    ];

    public function index(Request $request): JsonResponse
    {
        $lists = $this->withCounts($this->organization($request)->lists()->getQuery())->orderBy('name')->get();

        return response()->json(['data' => $lists->map(fn (ContactList $list): array => $this->present($list))]);
    }

    public function show(Request $request, int $list): JsonResponse
    {
        $model = $this->withCounts($this->organization($request)->lists()->getQuery())->findOrFail($list);

        return response()->json(['data' => $this->present($model)]);
    }

    public function store(ContactListRequest $request): JsonResponse
    {
        $list = $this->organization($request)->lists()->create($request->validated());

        if ($list->isSynced()) {
            $this->sources->sync($list);
        }

        return response()->json(['data' => $this->present($this->withCounts(ContactList::query())->findOrFail($list->id))], 201);
    }

    /**
     * Дополнительные поля подписчиков базы и их частые значения: из них собирается условие отбора рассылки.
     */
    public function fields(Request $request, int $list, ListFields $fields): JsonResponse
    {
        return response()->json(['data' => $fields->of($this->organization($request)->lists()->findOrFail($list))]);
    }

    /**
     * Сколько подписчиков базы получит рассылку с таким условием отбора.
     */
    public function count(SegmentRequest $request, int $list): JsonResponse
    {
        $model = $this->organization($request)->lists()->findOrFail($list);

        return response()->json(['data' => ['count' => $model->contacts()->deliverable()->inSegment($request->validated('segment'))->count()]]);
    }

    /**
     * Откуда можно взять подписчиков для синхронизируемой базы.
     */
    public function sources(Request $request): JsonResponse
    {
        $sources = collect($this->sources->available($this->organization($request)))
            ->map(fn (SubscriberSource $source): array => ['key' => $source->key(), 'label' => $source->label()])
            ->values();

        return response()->json(['data' => $sources]);
    }

    /**
     * Обновить синхронизируемую базу из источника сейчас.
     */
    public function sync(Request $request, int $list): JsonResponse
    {
        $model = $this->organization($request)->lists()->findOrFail($list);
        abort_unless($model->isSynced(), 422, 'Эта база не связана с источником: адреса в неё загружаются из файла');

        $result = $this->sources->sync($model);

        return response()->json(['data' => $this->present($this->withCounts(ContactList::query())->findOrFail($model->id)), 'result' => $result]);
    }

    public function update(ContactListRequest $request, int $list): JsonResponse
    {
        $model = $this->organization($request)->lists()->findOrFail($list);
        $model->update($request->validated());

        return $this->show($request, $model->id);
    }

    /**
     * Проверить все адреса базы заново, например после обновления списка одноразовых доменов.
     */
    public function check(Request $request, int $list): JsonResponse
    {
        $model = $this->organization($request)->lists()->findOrFail($list);
        $model->contacts()->update(['checked_at' => null]);
        CheckContactsJob::dispatch($model->id)->onQueue(config('sender.queue'));

        return $this->show($request, $model->id);
    }

    /**
     * Вместе с базой удаляются её подписчики. Отправленные письма остаются в журнале.
     */
    public function destroy(Request $request, int $list): JsonResponse
    {
        $this->organization($request)->lists()->findOrFail($list)->delete();

        return response()->json(['message' => 'База удалена']);
    }

    /**
     * @param  Builder<ContactList>  $query
     * @return Builder<ContactList>
     */
    private function withCounts(Builder $query): Builder
    {
        $counts = [
            'contacts',
            'contacts as subscribed_count' => fn (Builder $q) => $q->subscribed(),
            'contacts as pending_count' => fn (Builder $q) => $q->whereNull('unsubscribed_at')->pending(),
            'contacts as deliverable_count' => fn (Builder $q) => $q->deliverable(),
            'contacts as unchecked_count' => fn (Builder $q) => $q->whereNull('checked_at'),
        ];

        foreach (self::CHECKS as $status) {
            $counts['contacts as '.$status.'_count'] = fn (Builder $q) => $q->where('check_status', $status);
        }

        return $query->with('organization')->withCount($counts);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ContactList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'source' => $list->source,
            'source_label' => $list->isSynced() ? ($this->sources->available($list->organization)[$list->source] ?? null)?->label() : null,
            'synced_at' => $list->synced_at?->toIso8601String(),
            'require_confirmation' => (bool) $list->require_confirmation,
            'contacts_count' => (int) ($list->contacts_count ?? 0),
            'subscribed_count' => (int) ($list->subscribed_count ?? 0),
            'pending_count' => (int) ($list->pending_count ?? 0),
            'deliverable_count' => (int) ($list->deliverable_count ?? 0),
            'checks' => [
                'unchecked' => (int) ($list->unchecked_count ?? 0),
                ...collect(self::CHECKS)->mapWithKeys(fn (string $status): array => [$status => (int) ($list->{$status.'_count'} ?? 0)])->all(),
            ],
            'created_at' => $list->created_at?->toIso8601String(),
            'updated_at' => $list->updated_at?->toIso8601String(),
        ];
    }
}
