<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Исходящее служебное письмо сайта (transactional outbox).
 *
 * Запись создаётся в момент действия пользователя и не теряется, если Sender недоступен: письмо уходит
 * из очереди с повторами и тем же Idempotency-Key. sender_message_id связывает запись с письмом в Sender.
 */
class MailOutbox extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $table = 'mail_outbox';

    protected $fillable = [
        'uuid', 'template', 'fallback_template', 'to_email', 'data', 'idempotency_key', 'status', 'attempts',
        'last_error', 'sender_message_id', 'user_id', 'context', 'next_attempt_at', 'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'context' => 'array',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Данные без секретов (services.sender.redact_keys): пароли и одноразовые ссылки после отправки не хранятся.
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    public static function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        return array_diff_key($data, array_flip((array) config('services.sender.redact_keys', [])));
    }
}
