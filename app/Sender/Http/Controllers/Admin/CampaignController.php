<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\CampaignRequest;
use App\Sender\Http\Requests\Admin\TestCampaignRequest;
use App\Sender\Http\Resources\MessageResource;
use App\Sender\Models\Campaign;
use App\Sender\Services\CampaignSchedule;
use App\Sender\Services\CampaignService;
use App\Sender\Services\Warmup;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CampaignController extends Controller
{
    use ResolvesOrganization;

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly CampaignSchedule $schedule,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $campaigns = $this->organization($request)->campaigns()->with(['template:id,name', 'list:id,name'])->latest('id')->get();
        $stats = $this->campaigns->statsForMany($campaigns->reject(fn (Campaign $c): bool => $c->isDraft()));

        return response()->json([
            'data' => $campaigns->map(fn (Campaign $c): array => $this->present($c, stats: $stats[$c->id] ?? null)),
            'warmup' => app(Warmup::class)->status(),
        ]);
    }

    public function show(Request $request, int $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request, $campaign), true)]);
    }

    public function store(CampaignRequest $request): JsonResponse
    {
        $campaign = $this->organization($request)->campaigns()->create([...$request->validated(), 'status' => Campaign::STATUS_DRAFT]);

        return response()->json(['data' => $this->present($campaign->load(['template', 'list']))], 201);
    }

    public function update(CampaignRequest $request, int $campaign): JsonResponse
    {
        $model = $this->find($request, $campaign);
        abort_unless($model->isDraft(), 422, 'Запущенную рассылку изменить нельзя');
        $model->update($request->validated());

        return response()->json(['data' => $this->present($model->refresh()->load(['template', 'list']), true)]);
    }

    public function send(Request $request, int $campaign): JsonResponse
    {
        $model = $this->campaigns->start($this->find($request, $campaign));

        return response()->json(['data' => $this->present($model, true)]);
    }

    public function test(TestCampaignRequest $request, int $campaign): JsonResponse
    {
        $message = $this->campaigns->sendTest($this->find($request, $campaign), $request->string('to')->toString(), $request->validated('contact_email'));

        return (new MessageResource($message->load('template')))->response()->setStatusCode(201);
    }

    public function pause(Request $request, int $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($this->campaigns->pause($this->find($request, $campaign)), true)]);
    }

    public function resume(Request $request, int $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($this->campaigns->resume($this->find($request, $campaign)), true)]);
    }

    public function cancel(Request $request, int $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($this->campaigns->cancel($this->find($request, $campaign)), true)]);
    }

    public function destroy(Request $request, int $campaign): JsonResponse
    {
        $model = $this->find($request, $campaign);
        abort_if(in_array($model->status, [...Campaign::ACTIVE_STATUSES, Campaign::STATUS_PAUSED], true), 422, 'Рассылка запущена: сначала отмените её');
        $model->delete();

        return response()->json(['message' => 'Рассылка удалена']);
    }

    /**
     * Когда уйдёт следующая пачка с учётом окна отправки: вне окна это его ближайшее открытие.
     */
    private function nextBatchAt(Campaign $campaign): ?CarbonImmutable
    {
        if ($campaign->next_batch_at === null || ! in_array($campaign->status, Campaign::ACTIVE_STATUSES, true)) {
            return null;
        }

        $next = $campaign->next_batch_at->max(now());

        return $campaign->isDrip() ? $this->schedule->nextOpening($campaign, $next) : $next;
    }

    private function find(Request $request, int $campaign): Campaign
    {
        return $this->organization($request)->campaigns()->with(['template', 'list'])->findOrFail($campaign);
    }

    /**
     * @param  array<string, mixed>|null  $stats  посчитанная заранее статистика (список рассылок)
     * @return array<string, mixed>
     */
    private function present(Campaign $campaign, bool $detailed = false, ?array $stats = null): array
    {
        $stats ??= $campaign->isDraft() ? null : $this->campaigns->stats($campaign);

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'template' => $campaign->template ? ['id' => $campaign->template->id, 'name' => $campaign->template->name] : null,
            'list' => $campaign->list ? ['id' => $campaign->list->id, 'name' => $campaign->list->name] : null,
            'recipients_count' => $campaign->recipients_count,
            'track' => (bool) ($campaign->track ?? true),
            'send_mode' => $campaign->send_mode ?? Campaign::MODE_NOW,
            'batch_size' => $campaign->batch_size,
            'batch_interval' => $campaign->batch_interval,
            'daily_limit' => $campaign->daily_limit,
            'window_start' => $campaign->window_start,
            'window_end' => $campaign->window_end,
            'window_days' => $campaign->window_days,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'next_batch_at' => $this->nextBatchAt($campaign)?->toIso8601String(),
            'prepared' => $campaign->prepared_at !== null,
            'estimated_finish_at' => $detailed ? $this->campaigns->estimatedFinish($campaign)?->toIso8601String() : null,
            'stats' => $stats,
            'subscribed_count' => $detailed && $campaign->isDraft() ? ($campaign->list?->contacts()->deliverable()->inSegment($campaign->segment)->count() ?? 0) : null,
            'segment' => $campaign->segment,
            'started_at' => $campaign->started_at?->toIso8601String(),
            'finished_at' => $campaign->finished_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }
}
