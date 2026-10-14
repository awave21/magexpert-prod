<?php

namespace App\Sender\Http\Controllers\Api;

use App\Sender\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Операции над адресом во всех базах организации сразу (право на удаление данных).
 */
class ContactController extends Controller
{
    /**
     * Убирает адрес из всех баз организации, в том числе собираемых автоматически. Идемпотентно:
     * адреса нет в базах, ответ тот же. Отправленные письма остаются в журнале, а блок-лист и отписки
     * не трогаются: удалённый адрес не должен получать рассылки, если его снова добавят.
     */
    public function destroy(Request $request, string $email): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('sender_organization');

        $organization->contacts()->where('email', mb_strtolower(trim($email)))->delete();

        return response()->noContent();
    }
}
