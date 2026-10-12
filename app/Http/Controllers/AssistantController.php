<?php

namespace App\Http\Controllers;

use App\Http\Requests\AskAssistantRequest;
use App\Models\AssistantQuestion;
use App\Services\Assistant\LibraryAssistant;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ИИ-помощник в личном кабинете: вопрос по документам библиотеки, ответ со ссылками на страницы PDF.
 * Доступен всем вошедшим пользователям; от перерасхода защищает ограничение частоты запросов на маршруте.
 * Каждый вопрос попадает в журнал для админки, а ссылки на источники идут через documents.show, чтобы было видно, что открыли.
 */
class AssistantController extends Controller
{
    public function index(LibraryAssistant $assistant): Response
    {
        return Inertia::render('Cabinet/Assistant', [
            'available' => $assistant->isAvailable(),
        ]);
    }

    public function ask(AskAssistantRequest $request, LibraryAssistant $assistant): JsonResponse
    {
        if (! $assistant->isAvailable()) {
            return response()->json(['message' => 'ИИ-помощник скоро заработает.'], 503);
        }

        $question = trim($request->validated('question'));
        $started = hrtime(true);
        $answer = $assistant->answer($question);

        $record = AssistantQuestion::create([
            'user_id' => $request->user()->id,
            'question' => $question,
            'found' => $answer->found(),
            'sources' => array_map(fn (array $source): array => [
                'document_id' => $source['document_id'],
                'title' => $source['title'],
                'page' => $source['page'],
            ], $answer->sources),
            'model' => $answer->model,
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        $payload = $answer->toArray();
        $payload['sources'] = array_map(fn (array $source): array => [
            ...$source,
            'url' => route('documents.show', ['document' => $source['document_id'], 'page' => $source['page'], 'question' => $record->id]),
        ], $payload['sources']);

        return response()->json($payload);
    }
}
