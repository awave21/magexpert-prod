<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\OrganizationRequest;
use App\Sender\Http\Requests\Admin\TransferOwnershipRequest;
use App\Sender\Models\Organization;
use App\Sender\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Организация: название и владелец. Менять их может только владелец (см. AuthorizeRole).
 */
class OrganizationController extends Controller
{
    use ResolvesOrganization;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->organization($request))]);
    }

    public function update(OrganizationRequest $request): JsonResponse
    {
        $organization = $this->organization($request);
        $organization->forceFill(['name' => $request->validated('name')])->save();

        return response()->json(['data' => $this->present($organization)]);
    }

    /**
     * Владение переходит к другому активному участнику, прежний владелец становится администратором.
     * Обе записи блокируются до конца транзакции, и все условия проверяются заново: параллельные передачи
     * или правки команды не оставят организацию с двумя владельцами или без владельца.
     */
    public function transfer(TransferOwnershipRequest $request): JsonResponse
    {
        $organization = $this->organization($request);
        $current = $this->user($request);

        if (! $current->hasPassword()) {
            throw ValidationException::withMessages(['current_password' => TransferOwnershipRequest::PASSWORD_NOT_SET]);
        }

        if (! Hash::check($request->string('current_password')->toString(), $current->password)) {
            throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно']);
        }

        $targetId = (int) $request->validated('user_id');

        $target = $organization->getConnection()->transaction(function () use ($organization, $current, $targetId): User {
            // блокировки в порядке id, чтобы встречные передачи не ждали друг друга по кругу
            $locked = User::query()->whereKey(array_unique([$current->id, $targetId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $owner = $locked->get($current->id);
            $target = $locked->get($targetId);

            if ($owner === null || ! $owner->isOwner() || ! $owner->is_active || (int) $owner->organization_id !== (int) $organization->id) {
                abort(403, 'Это может делать только владелец организации');
            }

            if ($target === null || (int) $target->organization_id !== (int) $organization->id || ! $target->is_active || $target->id === $owner->id) {
                throw ValidationException::withMessages(['user_id' => 'Выберите активного участника команды, кроме себя']);
            }

            $owner->forceFill(['role' => User::ROLE_ADMIN])->save();
            $target->forceFill(['role' => User::ROLE_OWNER])->save();

            return $target;
        });

        return response()->json([
            'data' => $this->present($organization),
            'message' => "Владелец теперь {$target->name}. Вы остались администратором",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Organization $organization): array
    {
        $owner = $organization->owner()->first();

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
        ];
    }
}
