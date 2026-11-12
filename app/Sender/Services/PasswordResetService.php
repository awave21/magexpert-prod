<?php

namespace App\Sender\Services;

use App\Sender\Mail\PasswordResetMail;
use App\Sender\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetService
{
    public function request(string $email): void
    {
        $connection = (new User)->getConnection();
        $connection->transaction(function () use ($connection, $email): void {
            $user = User::query()->where('email', Str::lower(trim($email)))->lockForUpdate()->first();

            if ($user === null || ! $user->is_active || ! $user->organization?->is_active) {
                return;
            }

            $resets = $connection->table('sender_password_resets');
            $existing = (clone $resets)->where('user_id', $user->id)->first();

            if ($existing !== null && now()->subMinute()->lt($existing->created_at)) {
                return;
            }

            $token = Str::random(64);
            $resets->updateOrInsert(['user_id' => $user->id], [
                'token_hash' => hash('sha256', $token),
                'created_at' => now(),
            ]);

            $base = rtrim((string) (config('sender.ui_url') ?: url('/sender')), '/');
            $url = $base.'/reset-password#'.http_build_query(['token' => $token, 'email' => $user->email]);
            Mail::to($user->email)->queue(new PasswordResetMail($url));
        });
    }

    public function reset(string $email, string $token, string $password): bool
    {
        $connection = (new User)->getConnection();

        return $connection->transaction(function () use ($connection, $email, $token, $password): bool {
            $user = User::query()->where('email', Str::lower(trim($email)))->lockForUpdate()->first();

            if ($user === null || ! $user->is_active || ! $user->organization?->is_active) {
                return false;
            }

            $resets = $connection->table('sender_password_resets')->where('user_id', $user->id);
            $record = (clone $resets)->lockForUpdate()->first();

            if ($record === null || now()->subHour()->gte($record->created_at)
                || ! hash_equals($record->token_hash, hash('sha256', $token))) {
                return false;
            }

            $user->forceFill(['password' => $password, 'password_set' => true])->save();
            $user->tokens()->delete();
            $resets->delete();

            return true;
        });
    }
}
