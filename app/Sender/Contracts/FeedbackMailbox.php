<?php

namespace App\Sender\Contracts;

/**
 * Ящик, куда почтовые сервисы присылают отчёты о жалобах (FBL).
 */
interface FeedbackMailbox
{
    /**
     * Непрочитанные письма: UID => исходный текст письма.
     *
     * @return array<int, string>
     */
    public function unseen(int $limit): array;

    public function markSeen(int $uid): void;

    public function close(): void;
}
