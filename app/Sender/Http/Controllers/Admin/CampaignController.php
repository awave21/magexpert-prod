<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\CampaignRequest;
use App\Sender\Models\Campaign;
use App\Sender\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CampaignController extends Controller
{
    use ResolvesOrganization;

    public function __construct(private readonly CampaignService $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        $campaigns = $this->organization($request)->campaigns()->with(['template:id,name', 'list:id,name'])->latest('id')->get();

        return response()->json(['data' => $campaigns->map(fn (Campaign $c): array => $this->present($c))]);
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

    public function destroy(Request $request, int $campaign): JsonResponse
    {
        $model = $this->find($request, $campaign);
        abort_if($model->status === Campaign::STATUS_SENDING, 422, 'Рассылка отправляется, удалить её сейчас нельзя');
        $model->delete();

        return response()->json(['message' => 'Рассылка удалена']);
    }

    private function find(Request $request, int $campaign): Campaign
    {
        return $this->organization($request)->campaigns()->with(['template', 'list'])->findOrFail($campaign);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Campaign $campaign, bool $detailed = false): array
    {
        $stats = $campaign->isDraft() ? null : $this->campaigns->stats($campaign);

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'template' => $campaign->template ? ['id' => $campaign->template->id, 'name' => $campaign->template->name] : null,
            'list' => $campaign->list ? ['id' => $campaign->list->id, 'name' => $campaign->list->name] : null,
            'recipients_count' => $campaign->recipients_count,
            'stats' => $stats,
            'subscribed_count' => $detailed && $campaign->isDraft() ? ($campaign->list?->contacts()->subscribed()->count() ?? 0) : null,
            'started_at' => $campaign->started_at?->toIso8601String(),
            'finished_at' => $campaign->finished_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }
}
