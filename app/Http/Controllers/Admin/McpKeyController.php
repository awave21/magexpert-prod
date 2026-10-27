<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mcp\McpTokens;
use App\Models\McpAuditLog;
use App\Models\McpToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Личные ключи MCP и журнал действий ассистента. Доступно администраторам и менеджерам.
 */
class McpKeyController extends Controller
{
    public function index(Request $request, ?string $newKey = null): Response
    {
        $userId = $request->user()->id;
        $isAdmin = $request->user()->hasRole('admin');

        $keys = McpToken::query()->with('user:id,email')
            ->whereNull('revoked_at')
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $userId))
            ->latest()->get()
            ->map(fn (McpToken $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'owner' => $t->user?->email,
                'created_at' => $t->created_at?->toIso8601String(),
                'last_used_at' => $t->last_used_at?->toIso8601String(),
            ]);

        $log = McpAuditLog::query()->latest()->limit(50)->get()->map(function (McpAuditLog $l): array {
            return [
                'id' => $l->id,
                'at' => $l->created_at?->toIso8601String(),
                'tool' => $l->tool,
                'action' => $l->action,
                'subject' => $l->subject_type.($l->subject_id ? " #{$l->subject_id}" : ''),
                'label' => $l->details['label'] ?? $l->details['title'] ?? null,
                'user_id' => $l->user_id,
            ];
        });

        return Inertia::render('Admin/McpKeys', [
            'keys' => $keys,
            'log' => $log,
            'serverUrl' => url('/api/mcp'),
            'newKey' => $newKey,
        ]);
    }

    public function store(Request $request, McpTokens $tokens): Response|RedirectResponse
    {
        $data = $request->validate(['name' => 'nullable|string|max:100']);

        $active = McpToken::query()->where('user_id', $request->user()->id)->whereNull('revoked_at')->count();
        if ($active >= McpTokens::MAX_ACTIVE) {
            return back()->with('error', 'Слишком много действующих ключей: отзовите лишние');
        }

        $issued = $tokens->issue($request->user(), $data['name'] ?? '');

        return $this->index($request, $issued['plain']);
    }

    public function destroy(Request $request, McpToken $token, McpTokens $tokens): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin') || $token->user_id === $request->user()->id, 403);

        $tokens->revoke($token);

        return back()->with('success', 'Ключ отозван');
    }
}
