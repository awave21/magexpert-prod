<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_id',
        'amount',
        'currency',
        'status',
        'payment_system',
        'external_id',
        'order_id',
        'description',
        'metadata',
        'callback_data',
        'paid_at',
        'cancelled_at',
        'refunded_at',
        'refund_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'metadata' => 'array',
        'callback_data' => 'array',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    /**
     * Статусы платежей
     */
    const STATUS_PENDING = 'pending';      // Ожидает оплаты
    const STATUS_PROCESSING = 'processing'; // В процессе обработки
    const STATUS_COMPLETED = 'completed';   // Оплачен
    const STATUS_FAILED = 'failed';         // Ошибка оплаты
    const STATUS_CANCELLED = 'cancelled';   // Отменен
    const STATUS_REFUNDED = 'refunded';     // Возврат

    /**
     * Платежные системы
     */
    const SYSTEM_TEST = 'test';
    const SYSTEM_YOOKASSA = 'yookassa';
    const SYSTEM_STRIPE = 'stripe';
    const SYSTEM_ROBOKASSA = 'robokassa';
    const SYSTEM_SBERBANK = 'sberbank';

    /**
     * Связь с пользователем
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Связь с мероприятием
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Сгенерировать безопасный токен для платежа
     *
     * @return string
     */
    public function generateSecureToken(): string
    {
        return hash('sha256', $this->id . $this->created_at . config('app.key'));
    }

    /**
     * Проверить безопасный токен
     *
     * @param string $token
     * @return bool
     */
    public function verifySecureToken(string $token): bool
    {
        return hash_equals($this->generateSecureToken(), $token);
    }

    /**
     * Проверить, является ли платеж успешным
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Проверить, ожидает ли платеж оплаты
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Проверить, был ли платеж отменен
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Проверить, был ли сделан возврат
     *
     * @return bool
     */
    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    /**
     * Получить читаемое название статуса
     *
     * @return string
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Ожидает оплаты',
            self::STATUS_PROCESSING => 'В процессе',
            self::STATUS_COMPLETED => 'Оплачен',
            self::STATUS_FAILED => 'Ошибка оплаты',
            self::STATUS_CANCELLED => 'Отменен',
            self::STATUS_REFUNDED => 'Возврат',
            default => $this->status,
        };
    }

    /**
     * Получить читаемое название платежной системы
     *
     * @return string
     */
    public function getPaymentSystemLabel(): string
    {
        return match($this->payment_system) {
            self::SYSTEM_TEST => 'Тестовая система',
            self::SYSTEM_YOOKASSA => 'ЮKassa',
            self::SYSTEM_STRIPE => 'Stripe',
            self::SYSTEM_ROBOKASSA => 'Robokassa',
            self::SYSTEM_SBERBANK => 'Сбербанк',
            default => $this->payment_system,
        };
    }

    /**
     * Получить отформатированную сумму
     *
     * @return string
     */
    public function getFormattedAmount(): string
    {
        return number_format($this->amount, 2, '.', ' ') . ' ' . $this->currency;
    }

    /**
     * Скоуп для фильтрации по статусу
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Скоуп для успешных платежей
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Скоуп для платежей в ожидании
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Скоуп для платежей по мероприятию
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $eventId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Скоуп для платежей пользователя
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}