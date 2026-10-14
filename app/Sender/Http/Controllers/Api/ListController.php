<?php

namespace App\Sender\Http\Controllers\Api;

use App\Sender\Http\Requests\Api\UpsertListRequest;
use App\Sender\Models\ContactList;
use App\Sender\Models\Organization;
use App\Sender\Services\ContactUpsertService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Базы подписчиков для внешней системы: найти по названию или создать.
 */
class ListController extends Controller
{
    public const SYNCED = 'База с таким названием собирается автоматически, выберите другое название';

    public function upsert(UpsertListRequest $request, ContactUpsertService $service): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('sender_organization');
        [$list, $created] = $service->findOrCreateList($organization, $request->validated('name'), $request->validated('description'));

        // база из источника (например, пользователи сайта) пишется только источником: записи через API получали бы 422
        abort_if($list->isSynced(), 409, self::SYNCED);

        return response()->json(['data' => self::present($list)], $created ? 201 : 200);
    }

    /**
     * Базы организации; с ?name= только база с таким названием (без учёта регистра и пробелов по краям).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('sender_organization');
        $name = $request->query('name');

        $lists = $organization->lists()->orderBy('id')->get()
            ->when(is_string($name) && $name !== '', fn ($all) => $all->filter(fn (ContactList $list): bool => mb_strtolower(trim($list->name)) === mb_strtolower(trim($name))))
            ->values();

        return response()->json(['data' => $lists->map(fn (ContactList $list): array => self::present($list))->all()]);
    }

    /**
     * Создаёт базу (201 + Location). Название занято, значит 409 и ID существующей базы в list_id.
     */
    public function store(UpsertListRequest $request, ContactUpsertService $service): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('sender_organization');
        [$list, $created] = $service->findOrCreateList($organization, $request->validated('name'), $request->validated('description'));

        if (! $created) {
            return response()->json([
                'message' => 'База с таким названием уже есть',
                'list_id' => $list->id,
            ], 409);
        }

        return response()->json(['data' => self::present($list)], 201, ['Location' => url('/api/sender/v1/lists/'.$list->id)]);
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
