<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\ContactListRequest;
use App\Sender\Models\ContactList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ContactListController extends Controller
{
    use ResolvesOrganization;

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
        return $query->withCount([
            'contacts',
            'contacts as subscribed_count' => fn (Builder $q) => $q->whereNull('unsubscribed_at'),
        ]);
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
            'created_at' => $list->created_at?->toIso8601String(),
            'updated_at' => $list->updated_at?->toIso8601String(),
        ];
    }
}
