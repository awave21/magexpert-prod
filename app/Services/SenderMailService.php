<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Sender\Contracts\SenderClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Служебные письма сайта через собственный сервис рассылок (Sender).
 */
class SenderMailService
{
    /** Сколько спикеров передаётся в письмо отдельными переменными speaker_1_*, speaker_2_* … */
    public const MAX_SPEAKER_VARIABLES = 6;

    public function __construct(private readonly SenderClient $client) {}

    public function sendEventRegistrationEmail(Event $event, User $user, string $password = '', bool $isNewUser = false): bool
    {
        $event->loadMissing('speakers');

        $userName = trim($user->first_name.' '.$user->last_name);

        $speakers = $event->speakers->values();
        $isUpcoming = ! $event->is_archived;

        return $this->send($this->template('event_registration'), $user->email, array_filter([
            'user_name' => $userName,
            'first_name' => $user->first_name ?: ($userName !== '' ? explode(' ', $userName)[0] : ''),
            'user_email' => $user->email,
            'is_new_user' => $isNewUser,
            'password' => $password,
            'event_title' => $event->title,
            'event_slug' => $event->slug,
            'event_url' => url('/events/'.$event->slug),
            'event_type' => $this->translateEventType($event->event_type),
            'event_format' => $this->translateFormat($event->format),
            'event_location' => $event->location,
            'price' => $event->getFormattedPrice(),
            'event_image' => $this->absoluteUrl($event->image),
            'event_description' => $event->short_description ? trim(strip_tags((string) $event->short_description)) : null,
            'event_datetime' => $isUpcoming ? $this->formatDateTime($event->start_date, $event->start_time) : null,
            'speakers_count' => $speakers->count(),
            ...$this->speakerVariables($speakers),
            'start_date' => $event->is_archived ? null : $this->formatDate($event->start_date),
            'start_time' => $event->is_archived ? null : $this->formatTime($event->start_time),
            'end_date' => $event->is_archived ? null : $this->formatDate($event->end_date),
            'end_time' => $event->is_archived ? null : $this->formatTime($event->end_time),
            'speakers' => $event->speakers->map(fn ($speaker): string => trim(implode(', ', array_filter([
                $speaker->full_name,
                $speaker->position,
                $speaker->company,
            ]))))->implode('; '),
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== false));
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
        ]);
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
        ], fn ($value): bool => $value !== null && $value !== ''));
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
        ]);
    }

    public function sendApiRegistrationEmail(string $email, string $password, string $name = ''): bool
    {
        return $this->send($this->template('api_registration'), $email, [
            'user_email' => strtolower($email),
            'password' => $password,
            'name' => $name,
            'generated_at' => now()->format('d.m.Y H:i'),
        ]);
    }

    /**
     * Ключ или ID шаблона в Sender для письма сайта (настраивается в .env).
     */
    private function template(string $email): string
    {
        return (string) config('sender.client.templates.'.$email);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $template, string $to, array $data): bool
    {
        try {
            $result = $this->client->send(
                $template,
                $to,
                (string) config('sender.client.from_address'),
                config('sender.client.from_name'),
                $data,
            );
        } catch (Throwable $exception) {
            Log::error('Sender: не удалось отправить письмо', [
                'template' => $template,
                'email' => $to,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $accepted = in_array($result['status'], ['queued', 'sending', 'sent'], true);

        if (! $accepted) {
            Log::warning('Sender: письмо не принято', ['template' => $template, 'email' => $to, 'result' => $result]);
        }

        return $accepted;
    }

    /**
     * Спикеры плоскими переменными speaker_N_name, speaker_N_position, speaker_N_company, speaker_N_photo:
     * шаблон в Sender сам решает, какие показывать ({{#if speaker_1_name}}…{{/if}}).
     *
     * @param  iterable<int, \App\Models\Speaker>  $speakers
     * @return array<string, string|null>
     */
    private function speakerVariables(iterable $speakers): array
    {
        $variables = [];
        $number = 0;

        foreach ($speakers as $speaker) {
            if (++$number > self::MAX_SPEAKER_VARIABLES) {
                break;
            }

            $variables["speaker_{$number}_name"] = $speaker->full_name;
            $variables["speaker_{$number}_position"] = $speaker->position;
            $variables["speaker_{$number}_company"] = $speaker->company;
            $variables["speaker_{$number}_photo"] = $this->absoluteUrl($speaker->photo);
        }

        return $variables;
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
