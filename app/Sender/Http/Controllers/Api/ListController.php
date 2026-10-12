<?php

namespace App\Sender\Http\Controllers\Api;

use App\Sender\Http\Requests\Api\UpsertListRequest;
use App\Sender\Models\ContactList;
use App\Sender\Models\Organization;
use App\Sender\Services\ContactUpsertService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Базы подписчиков для внешней системы: найти по названию или создать.
 */
class ListController extends Controller
{
    public function upsert(UpsertListRequest $request, ContactUpsertService $service): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('sender_organization');
        [$list, $created] = $service->findOrCreateList($organization, $request->validated('name'), $request->validated('description'));

        return response()->json(['data' => self::present($list)], $created ? 201 : 200);
    }

    /**
     * @return array{id: int, name: string, description: string|null, require_confirmation: bool, contacts_count: int, subscribed_count: int, pending_count: int, created_at: string|null}
     */
    public static function present(ContactList $list): array
    {
        $list->loadCount([
            'contacts',
            'contacts as subscribed_count' => fn (Builder $q) => $q->subscribed(),
            'contacts as pending_count' => fn (Builder $q) => $q->whereNull('unsubscribed_at')->pending(),
        ]);

        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'require_confirmation' => (bool) $list->require_confirmation,
            'contacts_count' => (int) $list->contacts_count,
            'subscribed_count' => (int) $list->subscribed_count,
            'pending_count' => (int) $list->pending_count,
            'created_at' => $list->created_at?->toIso8601String(),
        ];
    }
}
