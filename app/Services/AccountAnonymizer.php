<?php

namespace App\Services;

use App\Models\User;
use App\Sender\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * «Удаление аккаунта» по просьбе пользователя: персональные данные стираются, а запись пользователя остаётся
 * обезличенной, чтобы сохранились его платежи и записи на мероприятия (они нужны для учёта оплат и возвратов).
 */
class AccountAnonymizer
{
    public function anonymize(User $user): void
    {
        $email = Str::lower((string) $user->email);
        $avatar = $user->avatar;

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'first_name' => 'Удалённый пользователь',
                'last_name' => null,
                'middle_name' => null,
                'email' => 'deleted-'.$user->id.'@mag-expert.invalid',
                'phone' => null,
                'company' => null,
                'position' => null,
                'specialization' => null,
                'city' => null,
                'avatar' => null,
                'newsletter_consent' => false,
                'email_verified_at' => null,
                // новый случайный пароль: войти в аккаунт больше нельзя, остальные сессии завершатся (AuthenticateSession)
                'password' => Str::random(64),
                'remember_token' => null,
            ])->save();

            $user->roles()->detach();
            // иначе через Яндекс или ВК можно было бы снова войти в обезличенный аккаунт
            $user->socialAccounts()->delete();
        });

        if ($avatar && ! str_starts_with($avatar, 'http')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $avatar));
        }

        $this->forgetInSender($email);
    }

    /**
     * Убирает адрес из баз подписчиков Sender, чтобы на него не приходили рассылки.
     */
    private function forgetInSender(string $email): void
    {
        try {
            Organization::query()
                ->where('slug', config('sender.client.organization'))
                ->first()
                ?->contacts()
                ->where('email', $email)
                ->delete();
        } catch (Throwable $exception) {
            Log::warning('Sender: не удалось убрать адрес удалённого аккаунта из баз', ['error' => $exception->getMessage()]);
        }
    }
}
