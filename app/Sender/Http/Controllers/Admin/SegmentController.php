<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\SaveSegmentRequest;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\Segment;
use App\Sender\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Сегменты: сохранённые условия отбора внутри базы. Состав считается на момент запроса.
 */
class SegmentController extends Controller
{
    use ResolvesOrganization;

    public function __construct(private readonly CampaignService $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        $segments = $this->organization($request)->segments()->with('list')->withCount('campaigns')->orderBy('name')->get();

        return response()->json(['data' => $segments->map(fn (Segment $segment): array => $this->present($segment))]);
    }

    public function show(Request $request, int $segment): JsonResponse
    {
        $model = $this->find($request, $segment);

        return response()->json(['data' => [...$this->present($model), 'campaigns' => $this->campaigns($model)]]);
    }

    public function store(SaveSegmentRequest $request): JsonResponse
    {
        $segment = $this->organization($request)->segments()->create([
            ...$request->safe()->only(['name', 'description', 'list_id']),
            'conditions' => Segment::cleanConditions($request->validated('conditions')),
        ]);

        return response()->json(['data' => $this->present($this->find($request, $segment->id))], 201);
    }

    public function update(SaveSegmentRequest $request, int $segment): JsonResponse
    {
        $model = $this->find($request, $segment);
        $model->update([
            ...$request->safe()->only(['name', 'description']),
            'conditions' => Segment::cleanConditions($request->validated('conditions')),
        ]);
        $model->campaigns()->update(['segment_name' => $model->name]);

        return $this->show($request, $model->id);
    }

    /**
     * Адреса базы остаются. Черновики с этим сегментом остаются без получателей: иначе они ушли бы всей базе.
     *
     * Запущенные рассылки (запланированные, идущие, на паузе): что с ними делать, решает человек — campaigns=cancel
     * отменяет их, campaigns=keep оставляет: письма подготовлены при запуске и уйдут тем же адресам.
     * Оставить нельзя, пока письма ещё готовятся: оставшиеся порции готовились бы уже без условий, то есть всей базе.
     */
    public function destroy(Request $request, int $segment): JsonResponse
    {
        $model = $this->find($request, $segment);
        $running = $model->campaigns()->whereIn('status', [...Campaign::ACTIVE_STATUSES, Campaign::STATUS_PAUSED])->get();
        $choice = $request->input('campaigns');

        if ($running->isNotEmpty() && ! in_array($choice, ['cancel', 'keep'], true)) {
            throw ValidationException::withMessages(['campaigns' => 'Сегмент выбран в запущенной рассылке: отменить её или оставить?']);
        }

        $preparing = $running->first(fn (Campaign $c): bool => $c->prepared_at === null);
        abort_if($choice === 'keep' && $preparing !== null, 422, "Для рассылки «{$preparing?->name}» по этому сегменту ещё готовятся письма. Подождите несколько минут или отмените рассылку");

        DB::connection($model->getConnectionName())->transaction(function () use ($model, $running, $choice): void {
            if ($choice === 'cancel') {
                $running->each(fn (Campaign $c) => $this->campaigns->cancel($c));
            }

            $model->campaigns()->where('status', Campaign::STATUS_DRAFT)->update(['list_id' => null, 'segment_id' => null, 'segment_name' => null]);
            $model->delete();
        });

        return response()->json(['message' => $choice === 'cancel' ? 'Сегмент удалён, рассылки отменены' : 'Сегмент удалён']);
    }

    /**
     * Кто сейчас в сегменте: получатели рассылки по нему.
     */
    public function contacts(Request $request, int $segment): JsonResponse
    {
        $model = $this->find($request, $segment);
        $query = $model->list->contacts()->deliverable()->matchingConditions($model->conditions)->latest('id');

        if ($request->filled('q')) {
            $raw = trim((string) $request->string('q'));
            $query->where(fn ($w) => $w->where('email', 'like', '%'.mb_strtolower($raw).'%')->orWhere('name', 'ilike', '%'.$raw.'%'));
        }

        $page = $query->paginate(50)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Contact $c): array => [
                'id' => $c->id,
                'email' => $c->email,
                'name' => $c->name,
                'data' => $c->data ?: (object) [],
                'tags' => $c->tags ?? [],
                'last_opened_at' => $c->last_opened_at?->toIso8601String(),
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function find(Request $request, int $segment): Segment
    {
        return $this->organization($request)->segments()->with('list')->withCount('campaigns')->findOrFail($segment);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaigns(Segment $segment): array
    {
        return $segment->campaigns()->latest('id')->limit(20)->get()->map(fn (Campaign $c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            'finished_at' => $c->finished_at?->toIso8601String(),
            'recipients_count' => $c->recipients_count,
            'prepared' => $c->prepared_at !== null,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Segment $segment): array
    {
        $contacts = $segment->list->contacts()->deliverable();

        return [
            'id' => $segment->id,
            'name' => $segment->name,
            'description' => $segment->description,
            'list' => ['id' => $segment->list->id, 'name' => $segment->list->name],
            'conditions' => $segment->conditions ?? [],
            'count' => (clone $contacts)->matchingConditions($segment->conditions)->count(),
            'list_count' => $contacts->count(),
            'campaigns_count' => (int) ($segment->campaigns_count ?? 0),
            'created_at' => $segment->created_at?->toIso8601String(),
            'updated_at' => $segment->updated_at?->toIso8601String(),
        ];
    }
}
