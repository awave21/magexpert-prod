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
     */
    public function transfer(TransferOwnershipRequest $request): JsonResponse
    {
        $organization = $this->organization($request);
        $owner = $this->user($request);

        if (! Hash::check($request->string('current_password')->toString(), $owner->password)) {
            throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно']);
        }

        $target = $organization->users()->whereKey((int) $request->validated('user_id'))->first();

        if ($target === null || ! $target->is_active || $target->id === $owner->id) {
            throw ValidationException::withMessages(['user_id' => 'Выберите активного участника команды, кроме себя']);
        }

        $organization->getConnection()->transaction(function () use ($owner, $target): void {
            $owner->forceFill(['role' => User::ROLE_ADMIN])->save();
            $target->forceFill(['role' => User::ROLE_OWNER])->save();
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
