<?php

namespace App\Services\Assistant;

use App\Models\MedicalLibrary;
use Illuminate\Support\Str;

/**
 * Заглушка для локальной разработки интерфейса: настоящих документов не читает,
 * а собирает правдоподобный ответ со сносками на последние документы библиотеки.
 */
class DemoLibraryAssistant implements LibraryAssistant
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function answer(string $question): AssistantAnswer
    {
        $documents = MedicalLibrary::query()
            ->whereNotNull('file_path')
            ->latest('publication_date')
            ->limit(3)
            ->get();

        if ($documents->isEmpty() || Str::contains(Str::lower($question), 'нет ответа')) {
            return AssistantAnswer::notFound();
        }

        $sources = $documents->values()->map(fn (MedicalLibrary $document, int $index): array => [
            'number' => $index + 1,
            'document_id' => $document->id,
            'title' => $document->title,
            'page' => $page = $document->id % 12 + 1,
            'language' => $document->language,
            'excerpt' => Str::limit(strip_tags((string) $document->description), 180) ?: 'Фрагмент документа появится здесь, когда подключим поиск по тексту PDF.',
            'url' => $document->file_url.'#page='.$page,
        ])->all();

        $second = min(2, count($sources));
        $text = 'Это демонстрационный ответ: настоящий поиск по документам ещё не подключён. '
            .'Здесь появится ответ на вопрос «'.Str::limit($question, 120).'», собранный из фрагментов библиотеки [1]. '
            ."Каждое утверждение будет со ссылкой на документ и страницу, откуда оно взято [{$second}].\n\n"
            .'Если в документах ответа нет, помощник так и скажет и не станет ничего придумывать'
            .(count($sources) > 2 ? ' [3].' : '.');

        return new AssistantAnswer($text, $sources);
    }
}
