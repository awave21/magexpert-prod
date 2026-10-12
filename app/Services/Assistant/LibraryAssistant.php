<?php

namespace App\Services\Assistant;

/**
 * ИИ-помощник кабинета: отвечает на вопрос по документам «Медицинской библиотеки» со ссылками на страницы.
 */
interface LibraryAssistant
{
    /**
     * Готов ли помощник отвечать (подключён ли поиск по документам).
     */
    public function isAvailable(): bool;

    public function answer(string $question): AssistantAnswer;
}
