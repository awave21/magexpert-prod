<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\WebhookRequest;
use App\Sender\Jobs\SendWebhookJob;
use App\Sender\Models\Webhook;
use App\Sender\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Throwable;

class WebhookController extends Controller
{
    use ResolvesOrganization;

    public function __construct(private readonly WebhookService $webhooks) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->organization($request)->webhooks()->latest('id')->get()->map(fn (Webhook $w): array => $this->present($w))]);
    }

    /**
     * Секрет для проверки подписи показывается один раз.
     */
    public function store(WebhookRequest $request): JsonResponse
    {
        $secret = Str::random(40);
        $webhook = $this->organization($request)->webhooks()->create([...$request->validated(), 'secret' => $secret]);
        $this->webhooks->forget($webhook->organization_id);

        return response()->json(['data' => $this->present($webhook), 'secret' => $secret], 201);
    }

    public function update(WebhookRequest $request, int $webhook): JsonResponse
    {
        $model = $this->organization($request)->webhooks()->findOrFail($webhook);
        $model->update($request->validated());
        $this->webhooks->forget($model->organization_id);

        return response()->json(['data' => $this->present($model)]);
    }

    public function destroy(Request $request, int $webhook): JsonResponse
    {
        $model = $this->organization($request)->webhooks()->findOrFail($webhook);
        $model->delete();
        $this->webhooks->forget($model->organization_id);

        return response()->json(['message' => 'Вебхук удалён']);
    }

    /**
     * Пробное событие сразу, без очереди: ответ приложения виден в результате.
     */
    public function test(Request $request, int $webhook): JsonResponse
    {
        $model = $this->organization($request)->webhooks()->findOrFail($webhook);

        try {
            (new SendWebhookJob($model->id, ['event' => 'test', 'occurred_at' => now()->toIso8601String()]))->handle($this->webhooks);
        } catch (Throwable) {
            // ошибка уже записана в last_error
        }

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Webhook $webhook): array
    {
        return [
            'id' => $webhook->id,
            'url' => $webhook->url,
            'events' => $webhook->events ?? [],
            'is_active' => $webhook->is_active ?? true,
            'last_status' => $webhook->last_status,
            'last_error' => $webhook->last_error,
            'last_sent_at' => $webhook->last_sent_at?->toIso8601String(),
        ];
    }
}
