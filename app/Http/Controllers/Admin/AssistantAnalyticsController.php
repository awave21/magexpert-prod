<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssistantQuestion;
use App\Models\LibraryDocumentOpen;
use App\Models\MedicalLibrary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Раздел «ИИ-помощник» в админке: что спрашивают, на что в библиотеке нет ответа и какие документы открывают.
 */
class AssistantAnalyticsController extends Controller
{
    private const array PERIODS = [7, 30, 90, 365];

    public function index(Request $request): Response
    {
        $days = in_array($request->integer('period'), self::PERIODS, true) ? $request->integer('period') : 30;
        $since = now()->subDays($days)->startOfDay();
        $status = in_array($request->input('status'), ['found', 'missing'], true) ? $request->input('status') : 'all';
        $search = trim((string) $request->input('search'));

        $questions = AssistantQuestion::query()
            ->with(['user:id,first_name,last_name,middle_name,email', 'opens:id,assistant_question_id,medical_library_id,page'])
            ->where('created_at', '>=', $since)
            ->when($status !== 'all', fn (Builder $query) => $query->where('found', $status === 'found'))
            ->when($search !== '', fn (Builder $query) => $query->whereRaw('question ILIKE ?', ['%'.addcslashes($search, '%_\\').'%']))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AssistantQuestion $question): array => [
                'id' => $question->id,
                'question' => $question->question,
                'found' => $question->found,
                'asked_at' => $question->created_at->format('d.m.Y H:i'),
                'duration' => round($question->duration_ms / 1000, 1),
                'user' => $question->user ? [
                    'id' => $question->user->id,
                    'name' => $question->user->full_name,
                    'email' => $question->user->email,
                ] : null,
                'sources' => collect($question->sources)->map(fn (array $source): array => [
                    ...$source,
                    'opened' => $question->opens->contains(fn (LibraryDocumentOpen $open): bool => $open->medical_library_id === $source['document_id'] && $open->page === $source['page']),
                ])->all(),
            ]);

        return Inertia::render('Admin/Assistant', [
            'filters' => ['period' => $days, 'status' => $status, 'search' => $search],
            'summary' => $this->summary($since),
            'missing' => $this->missing($since),
            'documents' => $this->documents($since),
            'questions' => $questions,
        ]);
    }

    /**
     * @return array{questions: int, found: int, users: int, opens_assistant: int, opens_library: int, cost: float}
     */
    private function summary(Carbon $since): array
    {
        $totals = AssistantQuestion::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('count(*) as questions, count(*) filter (where found) as found, count(distinct user_id) as users')
            ->first();

        $opens = LibraryDocumentOpen::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $prices = config('services.assistant.prices');
        $cost = AssistantQuestion::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('model, sum(input_tokens) as input, sum(output_tokens) as output')
            ->groupBy('model')
            ->get()
            ->sum(fn ($row): float => isset($prices[$row->model])
                ? ($row->input * $prices[$row->model][0] + $row->output * $prices[$row->model][1]) / 1_000_000
                : 0.0);

        return [
            'questions' => (int) $totals->questions,
            'found' => (int) $totals->found,
            'users' => (int) $totals->users,
            'opens_assistant' => (int) ($opens[LibraryDocumentOpen::FROM_ASSISTANT] ?? 0),
            'opens_library' => (int) ($opens[LibraryDocumentOpen::FROM_LIBRARY] ?? 0),
            'cost' => round($cost, 2),
        ];
    }

    /**
     * Вопросы без ответа в библиотеке, одинаковые склеены: подсказка, каких документов не хватает.
     *
     * @return array<int, array{question: string, times: int, last_asked: string}>
     */
    private function missing(Carbon $since): array
    {
        return AssistantQuestion::query()
            ->where('created_at', '>=', $since)
            ->where('found', false)
            ->selectRaw('lower(trim(question)) as normalized, min(question) as question, count(*) as times, max(created_at) as last_asked')
            ->groupByRaw('lower(trim(question))')
            ->orderByDesc('times')
            ->orderByDesc('last_asked')
            ->limit(15)
            ->get()
            ->map(fn ($row): array => [
                'question' => $row->question,
                'times' => (int) $row->times,
                'last_asked' => Carbon::parse($row->last_asked)->format('d.m.Y'),
            ])
            ->all();
    }

    /**
     * Документы, которые чаще всего открывают и на которые чаще всего ссылается помощник.
     *
     * @return array<int, array{id: int, title: string, opens_assistant: int, opens_library: int, cited: int}>
     */
    private function documents(Carbon $since): array
    {
        $opens = LibraryDocumentOpen::query()
            ->where('created_at', '>=', $since)
            ->selectRaw("medical_library_id, count(*) filter (where source = 'assistant') as assistant, count(*) filter (where source = 'library') as library")
            ->groupBy('medical_library_id')
            ->get()
            ->keyBy('medical_library_id');

        $cited = DB::table('assistant_questions')
            ->crossJoin(DB::raw('jsonb_array_elements(assistant_questions.sources) as source'))
            ->where('assistant_questions.created_at', '>=', $since)
            ->selectRaw("(source->>'document_id')::bigint as document_id, count(*) as total")
            ->groupBy('document_id')
            ->pluck('total', 'document_id');

        $ids = $opens->keys()->merge($cited->keys())->unique();
        $titles = MedicalLibrary::query()->whereIn('id', $ids)->pluck('title', 'id');

        return $ids
            ->map(fn (int $id): array => [
                'id' => $id,
                'title' => Str::limit((string) ($titles[$id] ?? 'Документ удалён'), 120),
                'opens_assistant' => (int) ($opens[$id]->assistant ?? 0),
                'opens_library' => (int) ($opens[$id]->library ?? 0),
                'cited' => (int) ($cited[$id] ?? 0),
            ])
            ->sortByDesc(fn (array $row): int => $row['opens_assistant'] + $row['opens_library'] + $row['cited'])
            ->take(10)
            ->values()
            ->all();
    }
}
