<?php

namespace App\Sender\Http\Controllers\Api;

use App\Sender\Http\Requests\Api\BatchContactsRequest;
use App\Sender\Http\Requests\Api\UpsertContactRequest;
use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Models\Organization;
use App\Sender\Services\ContactUpsertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Подписчики базы для внешней системы, которая держит базу в актуальном состоянии: добавить или обновить
 * (по одному и пакетом), посмотреть, удалить. База должна принадлежать организации API-ключа.
 */
class ListContactController extends Controller
{
    public const SYNCED = 'Эта база собирается автоматически, менять её через API нельзя';

    public function __construct(private readonly ContactUpsertService $service) {}

    public function upsert(UpsertContactRequest $request, int $list): JsonResponse
    {
        $model = $this->writableList($request, $list);
        $result = $this->service->upsert($model, $request->validated());

        return response()->json(['data' => $result], $result['created'] ? 201 : 200);
    }

    /**
     * Канонический адрес подписчика: PUT /lists/{list}/contacts/{email}. Создаёт (201 + Location) или обновляет (200).
     * База, которая собирается автоматически, отвечает 409.
     */
    public function put(UpsertContactRequest $request, int $list, string $email): JsonResponse
    {
        $model = $this->writableList($request, $list, 409);
        $result = $this->service->upsert($model, $request->validated());

        return response()->json(['data' => $result], $result['created'] ? 201 : 200, $result['created']
            ? ['Location' => url("/api/sender/v1/lists/{$list}/contacts/".rawurlencode($result['email']))]
            : []);
    }

    /**
     * Корректные строки применяются, ошибочные возвращаются в invalid с номером строки (с нуля) — пакет из-за них не отклоняется.
     */
    public function batch(BatchContactsRequest $request, int $list): JsonResponse
    {
        $model = $this->writableList($request, $list);
        $valid = [];
        $invalid = [];

        foreach ($request->contacts() as $index => $row) {
            if (! is_array($row) || array_is_list($row) && $row !== []) {
                $invalid[] = ['index' => $index, 'email' => null, 'errors' => ['contact' => ['Подписчик передаётся объектом {"email": …}']]];

                continue;
            }

            [$data, $errors] = UpsertContactRequest::validateRow($row);

            if ($data === null) {
                $email = is_string($row['email'] ?? null) ? mb_strtolower(trim($row['email'])) : null;
                $invalid[] = ['index' => $index, 'email' => $email, 'errors' => $errors];

                continue;
            }

            $valid[] = $data;
        }

        return response()->json(['data' => [...$this->service->batch($model, $valid), 'invalid' => $invalid]]);
    }

    public function show(Request $request, int $list, string $email): JsonResponse
    {
        $contact = $this->contact($this->list($request, $list), $email);

        $data = (array) ($contact->data ?? []);
        $base = ['phone', 'city'];

        return response()->json(['data' => [
            'email' => $contact->email,
            'name' => $contact->name,
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            // дополнительные атрибуты без базовых полей; data оставлено прежним (полный набор) для совместимости
            'attributes' => array_diff_key($data, array_flip($base)) ?: (object) [],
            'data' => $contact->data ?: (object) [],
            'tags' => $contact->tags ?? [],
            'status' => ContactUpsertService::status($contact),
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ]]);
    }

    /**
     * Отправленные подписчику письма остаются в журнале.
     */
    public function destroy(Request $request, int $list, string $email): Response
    {
        $model = $this->writableList($request, $list);
        $this->contact($model, $email)->delete();
        $model->touch();

        return response()->noContent();
    }

    private function list(Request $request, int $list): ContactList
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('sender_organization');
        $model = $organization->lists()->find($list);
        abort_if($model === null, 404, 'База не найдена');

        return $model;
    }

    private function writableList(Request $request, int $list, int $syncedStatus = 422): ContactList
    {
        $model = $this->list($request, $list);
        abort_if($model->isSynced(), $syncedStatus, self::SYNCED);

        return $model;
    }

    private function contact(ContactList $list, string $email): Contact
    {
        $contact = $list->contacts()->where('email', mb_strtolower(trim($email)))->first();
        abort_if($contact === null, 404, 'Подписчик не найден');

        return $contact;
    }
}
