<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Middleware\RecordAuditLog;
use App\Sender\Http\Requests\Admin\UserRequest;
use App\Sender\Models\AuditLog;
use App\Sender\Models\McpToken;
use App\Sender\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Команда организации: кто входит в Sender и с какими правами, плюс журнал действий.
 */
class UserController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): JsonResponse
    {
        $users = $this->organization($request)->users()->withMax('tokens', 'last_used_at')->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn (User $user): array => $this->present($user))]);
    }

    /**
     * Пароль генерируется и показывается один раз: передайте его человеку, сменить его можно повторным созданием командой.
     */
    public function store(UserRequest $request): JsonResponse
    {
        $password = Str::password(14, symbols: false);
        $user = $this->organization($request)->users()->create([...$request->validated(), 'password' => $password, 'password_set' => true, 'is_active' => true]);

        return response()->json(['data' => $this->present($user), 'password' => $password], 201);
    }

    /**
     * Запись участника блокируется до конца транзакции, проверки идут по заблокированной записи:
     * параллельная передача владения не даст изменить или отключить нового владельца.
     * Отключённый участник теряет все входы и ключи MCP.
     */
    public function update(UserRequest $request, int $user): JsonResponse
    {
        $organization = $this->organization($request);
        $currentId = $this->user($request)->id;
        $data = $request->validated();

        $model = $organization->getConnection()->transaction(function () use ($organization, $user, $currentId, $data): User {
            $model = $organization->users()->whereKey($user)->lockForUpdate()->firstOrFail();

            abort_if($model->isOwner(), 403, 'Владельца организации изменить или отключить нельзя');

            if ($model->id === $currentId && (($data['role'] ?? User::ROLE_ADMIN) !== User::ROLE_ADMIN || ($data['is_active'] ?? true) === false)) {
                abort(422, 'Свою роль и доступ изменить нельзя: попросите другого администратора');
            }

            $model->fill($data);

            // владелец тоже администратор
            $admins = $organization->users()->where('is_active', true)->whereIn('role', User::ADMIN_ROLES)->whereKeyNot($model->id)->lockForUpdate()->pluck('id')->count();
            abort_if($admins === 0 && (! $model->is_active || ! $model->isAdmin()), 422, 'В организации должен остаться хотя бы один администратор');

            $model->save();

            if (! $model->is_active) {
                $model->tokens()->delete();
                McpToken::query()->where('user_id', $model->id)->active()->update(['revoked_at' => now()]);
            }

            return $model;
        });

        return response()->json(['data' => $this->present($model)]);
    }

    public function auditLog(Request $request): JsonResponse
    {
        $page = $this->organization($request)->auditLogs()->with('user:id,name,email')->latest('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => RecordAuditLog::ACTIONS[$log->action] ?? $log->action,
                'subject' => $log->subject,
                'user' => $log->user?->name ?? 'удалённый пользователь',
                'ip' => $log->ip,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? User::ROLE_ADMIN,
            'is_owner' => $user->isOwner(),
            'is_active' => (bool) $user->is_active,
            'last_seen_at' => $user->tokens_max_last_used_at ? Carbon::parse($user->tokens_max_last_used_at)->toIso8601String() : null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
