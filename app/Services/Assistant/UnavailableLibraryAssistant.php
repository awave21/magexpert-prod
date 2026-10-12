<?php

namespace App\Services\Assistant;

use LogicException;

/**
 * Поиск по документам ещё не подключён: страница показывает, что помощник скоро заработает.
 */
class UnavailableLibraryAssistant implements LibraryAssistant
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function answer(string $question): AssistantAnswer
    {
        throw new LogicException('ИИ-помощник не подключён.');
    }
}
