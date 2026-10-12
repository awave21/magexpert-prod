<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\StoreMcpTokenRequest;
use App\Sender\Mcp\McpTokens;
use App\Sender\Models\AuditLog;
use App\Sender\Models\McpToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Личные ключи MCP для подключения ИИ-ассистентов: каждый пользователь управляет только своими ключами.
 */
class McpTokenController extends Controller
{
    use ResolvesOrganization;

    /**
     * Маршруты самообслуживания: доступны любой роли (нужно добавить в READ_ONLY у AuthorizeRole).
     */
    public const SELF_SERVICE_ROUTES = ['sender.admin.mcp-tokens.store', 'sender.admin.mcp-tokens.destroy'];

    public function __construct(private readonly McpTokens $tokens) {}

    public function index(Request $request): JsonResponse
    {
        $tokens = McpToken::query()->where('user_id', $this->user($request)->id)->active()->latest('id')->get();

        return response()->json([
            'data' => $tokens->map(fn (McpToken $token): array => $this->present($token))->values(),
            'url' => $this->tokens->url(),
        ]);
    }

    public function store(StoreMcpTokenRequest $request): JsonResponse
    {
        $user = $this->user($request);

        if (McpToken::query()->where('user_id', $user->id)->active()->count() >= McpTokens::MAX_ACTIVE) {
            return response()->json(['message' => 'У вас уже '.McpTokens::MAX_ACTIVE.' ключей: отзовите ненужные'], 422);
        }

        $result = $this->tokens->issue($user, $request->string('name')->toString());
        $this->audit($request, 'mcp.tokens.store', $result['token']->name);

        return response()->json([
            'data' => $this->present($result['token']),
            'token' => $result['plain'],
            'url' => $this->tokens->url(),
        ], 201);
    }

    public function destroy(Request $request, int $token): JsonResponse
    {
        $model = McpToken::query()->where('user_id', $this->user($request)->id)->active()->findOrFail($token);
        $this->tokens->revoke($model);
        $this->audit($request, 'mcp.tokens.destroy', $model->name);

        return response()->json(['message' => 'Ключ отозван']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(McpToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }

    private function audit(Request $request, string $action, string $subject): void
    {
        $user = $this->user($request);

        AuditLog::query()->create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject' => $subject,
            'ip' => $request->ip(),
        ]);
    }
}
