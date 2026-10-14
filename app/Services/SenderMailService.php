<?php

namespace App\Services;

use App\Jobs\DeliverOutboxMail;
use App\Models\Event;
use App\Models\MailOutbox;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Служебные письма сайта через сервис рассылок (Sender).
 *
 * Письмо не отправляется в запросе пользователя: оно записывается в исходящие (MailOutbox) вместе с действием
 * и уходит из очереди с повторами (DeliverOutboxMail). Поэтому недоступность Sender письмо не теряет, а методы
 * возвращают true, когда письмо принято к отправке.
 */
class SenderMailService
{
    public function sendEventRegistrationEmail(Event $event, User $user, bool $isNewUser = false): bool
    {
        $event->loadMissing('speakers');

        $userName = trim($user->first_name.' '.$user->last_name);

        $speakers = $event->speakers->values();
        $isUpcoming = ! $event->is_archived;

        $data = array_filter([
            'user_name' => $userName,
            'first_name' => $user->first_name ?: ($userName !== '' ? explode(' ', $userName)[0] : ''),
            'user_email' => $user->email,
            'is_new_user' => $isNewUser,
            // пароль в письмо не попадает: новому пользователю уходит одноразовая ссылка «Задать пароль»
            'set_password_url' => $isNewUser ? app(PasswordSetupLink::class)->url($user) : null,
            'set_password_expires_in' => $isNewUser ? app(PasswordSetupLink::class)->expiresInMinutes() : null,
            'event_title' => $event->title,
            'event_slug' => $event->slug,
            'event_url' => url('/events/'.$event->slug),
            'event_type' => $this->translateEventType($event->event_type),
            'event_format' => $this->translateFormat($event->format),
            'event_location' => $event->location,
            'event_city' => $event->city,
            'price' => $event->getFormattedPrice(),
            'event_image' => $this->absoluteUrl($event->image),
            'event_description' => $event->short_description ? trim(strip_tags((string) $event->short_description)) : null,
            'event_datetime' => $isUpcoming ? $this->formatDateTime($event->start_date, $event->start_time) : null,
            'speakers_count' => $speakers->count(),
            'speakers' => $speakers->isEmpty() ? null : $speakers->map(fn ($speaker): array => array_filter([
                'name' => $speaker->full_name,
                'regalia' => $speaker->regalia,
                'position' => $speaker->position,
                'company' => $speaker->company,
                'photo' => $this->absoluteUrl($speaker->photo),
            ], fn ($value): bool => filled($value)))->values()->all(),
            'start_date' => $event->is_archived ? null : $this->formatDate($event->start_date),
            'start_time' => $event->is_archived ? null : $this->formatTime($event->start_time),
            'end_date' => $event->is_archived ? null : $this->formatDate($event->end_date),
            'end_time' => $event->is_archived ? null : $this->formatTime($event->end_time),
            'speakers_text' => $event->speakers->map(fn ($speaker): string => trim(implode(', ', array_filter([
                $speaker->full_name,
                $speaker->regalia,
                $speaker->position,
                $speaker->company,
            ]))))->implode('; '),
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== false);

        // у мероприятия может быть свой шаблон (ID или алиас из Sender); не принят, значит уходит стандартное письмо
        $standard = $this->template('event_registration');
        $custom = trim((string) $event->letter_draft_id);
        $template = $custom !== '' ? $custom : $standard;

        return $this->send(
            $template,
            $user->email,
            $data,
            $this->registrationKey($event, $user, $template),
            ['kind' => 'event_registration', 'event_id' => $event->id, 'user_id' => $user->id],
            $custom !== '' && $custom !== $standard ? $standard : null,
        );
    }

    /**
     * Ключ идемпотентности письма о регистрации: повтор запроса (обрыв сети, повтор задачи) не отправит второе письмо.
     * Ключ суточный: осознанная повторная регистрация на другой день письмо получит.
     */
    private function registrationKey(Event $event, User $user, string $template): string
    {
        return 'event-registration:'.$event->id.':'.$user->id.':'.$template.':'.now()->format('Y-m-d');
    }

    /**
     * Ссылка для смены пароля. Пароль меняет сам человек, перейдя по ссылке.
     */
    public function sendPasswordResetLink(User $user, string $resetUrl, int $expiresInMinutes): bool
    {
        return $this->send($this->template('password_reset_link'), $user->email, [
            'first_name' => (string) $user->first_name,
            'user_email' => strtolower($user->email),
            'reset_url' => $resetUrl,
            'expires_in' => $expiresInMinutes,
        ], null, ['kind' => 'password_reset', 'user_id' => $user->id]);
    }

    /**
     * Приветствие после регистрации на сайте. Пароль пользователь придумал сам, поэтому в письме его нет.
     */
    public function sendWelcomeEmail(User $user): bool
    {
        $name = trim($user->first_name.' '.$user->last_name);

        return $this->send($this->template('welcome'), $user->email, array_filter([
            'first_name' => $user->first_name,
            'name' => $name,
            'user_name' => $name,
            'user_email' => strtolower($user->email),
            'site_url' => url('/'),
            // кнопка «Подтвердить email» в письме показывается только тем, у кого он ещё не подтверждён
            'verify_url' => $user->exists && ! $user->hasVerifiedEmail() ? app(EmailConfirmation::class)->url($user) : null,
        ], fn ($value): bool => $value !== null && $value !== ''), null, ['kind' => 'welcome', 'user_id' => $user->id]);
    }

    /**
     * Повторная ссылка подтверждения email (кнопка в личном кабинете).
     */
    public function sendEmailConfirmation(User $user): bool
    {
        return $this->send($this->template('email_confirmation'), $user->email, [
            'first_name' => (string) $user->first_name,
            'user_email' => strtolower($user->email),
            'verify_url' => app(EmailConfirmation::class)->url($user),
        ], null, ['kind' => 'email_confirmation', 'user_id' => $user->id]);
    }

    /**
     * Учётную запись создали через API: человек сам задаёт пароль по одноразовой ссылке.
     */
    public function sendApiRegistrationEmail(User $user): bool
    {
        $link = app(PasswordSetupLink::class);

        return $this->send($this->template('api_registration'), $user->email, [
            'user_email' => strtolower($user->email),
            'name' => trim($user->first_name.' '.$user->last_name),
            'set_password_url' => $link->url($user),
            'set_password_expires_in' => $link->expiresInMinutes(),
            'generated_at' => now()->format('d.m.Y H:i'),
        ], null, ['kind' => 'api_registration', 'user_id' => $user->id]);
    }

    /**
     * Ключ или ID шаблона в Sender для письма сайта (настраивается в .env).
     */
    private function template(string $email): string
    {
        return (string) config('services.sender.templates.'.$email);
    }

    /**
     * Записывает письмо в исходящие и ставит доставку в очередь.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context  чем вызвано письмо (для поиска в поддержке): kind, event_id, user_id
     */
    private function send(string $template, string $to, array $data, ?string $idempotencyKey = null, array $context = [], ?string $fallbackTemplate = null): bool
    {
        $uuid = (string) Str::uuid();

        try {
            $entry = MailOutbox::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey ?? 'mail:'.$uuid],
                [
                    'uuid' => $uuid,
                    'template' => $template,
                    'fallback_template' => $fallbackTemplate,
                    'to_email' => mb_strtolower(trim($to)),
                    'data' => $data,
                    'status' => MailOutbox::STATUS_PENDING,
                    'user_id' => $context['user_id'] ?? null,
                    // request_id исходного запроса пользователя: по нему находят и лог сайта, и письмо в Sender
                    'context' => $context === [] ? null : [...$context, 'request_id' => Context::get('request_id')],
                ],
            );
        } catch (Throwable $exception) {
            Log::error('Sender: не удалось записать письмо в исходящие', ['template' => $template, 'error' => $exception->getMessage()]);

            return false;
        }

        // повтор того же действия (двойной клик, повторный запрос) не создаёт второго письма
        if ($entry->wasRecentlyCreated) {
            $this->dispatchDelivery($entry);
        }

        return true;
    }

    /**
     * Доставка идёт из очереди. Сбой самой постановки (например, очередь недоступна) письма не теряет:
     * запись остаётся pending, её подберёт mail:outbox-retry.
     */
    private function dispatchDelivery(MailOutbox $entry): void
    {
        try {
            DeliverOutboxMail::dispatch($entry->id)->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('Sender: письмо записано, но доставка не поставлена в очередь', ['outbox_id' => $entry->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Картинки на сайте хранятся как /storage/…: в письме нужен полный адрес.
     */
    private function absoluteUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return preg_match('~^https?://~i', $path) ? $path : url($path);
    }

    private function formatDateTime(mixed $date, mixed $time): ?string
    {
        $date = $this->formatDate($date);

        if ($date === null) {
            return null;
        }

        $time = $this->formatTime($time);

        return $time === null ? $date : $date.', '.$time;
    }

    private function formatDate(mixed $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        return Carbon::parse($date)->format('d.m.Y');
    }

    private function formatTime(mixed $time): ?string
    {
        return empty($time) ? null : substr((string) $time, 0, 5);
    }

    private function translateEventType(?string $type): ?string
    {
        return [
            'webinar' => 'Вебинар',
            'conference' => 'Конференция',
            'course' => 'Курс',
            'workshop' => 'Мастер-класс',
            'seminar' => 'Семинар',
            'other' => 'Другое',
        ][$type] ?? $type;
    }

    private function translateFormat(?string $format): ?string
    {
        return [
            'online' => 'Онлайн',
            'offline' => 'Офлайн',
            'hybrid' => 'Гибрид',
        ][$format] ?? $format;
    }
}
