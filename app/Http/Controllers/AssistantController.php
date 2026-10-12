<?php

namespace App\Http\Controllers;

use App\Http\Requests\AskAssistantRequest;
use App\Services\Assistant\LibraryAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ИИ-помощник в личном кабинете: вопрос по документам библиотеки, ответ со ссылками на страницы PDF.
 */
class AssistantController extends Controller
{
    public function index(Request $request, LibraryAssistant $assistant): Response
    {
        return Inertia::render('Cabinet/Assistant', [
            'hasAccess' => $request->user()->canUseAssistant(),
            'available' => $assistant->isAvailable(),
        ]);
    }

    public function ask(AskAssistantRequest $request, LibraryAssistant $assistant): JsonResponse
    {
        if (! $assistant->isAvailable()) {
            return response()->json(['message' => 'ИИ-помощник скоро заработает.'], 503);
        }

        return response()->json($assistant->answer(trim($request->validated('question')))->toArray());
    }
}
