<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\ContactListRequest;
use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Models\ContactList;
use App\Sender\Services\EmailChecker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ContactListController extends Controller
{
    use ResolvesOrganization;

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

        return response()->json(['data' => $this->present($list)], 201);
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
            'contacts as subscribed_count' => fn (Builder $q) => $q->whereNull('unsubscribed_at'),
            'contacts as deliverable_count' => fn (Builder $q) => $q->deliverable(),
            'contacts as unchecked_count' => fn (Builder $q) => $q->whereNull('checked_at'),
        ];

        foreach (self::CHECKS as $status) {
            $counts['contacts as '.$status.'_count'] = fn (Builder $q) => $q->where('check_status', $status);
        }

        return $query->withCount($counts);
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
            'contacts_count' => (int) ($list->contacts_count ?? 0),
            'subscribed_count' => (int) ($list->subscribed_count ?? 0),
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
