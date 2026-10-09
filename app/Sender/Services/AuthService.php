<?php

namespace App\Sender\Services;

use App\Sender\Models\Organization;
use App\Sender\Models\User;
use App\Sender\Models\UserToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    private const PREFIX = 'mxu_';

    private const TTL_DAYS = 30;

    /**
     * @return array{user: User, token: string}|null
     */
    public function login(string $email, string $password): ?array
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        if ($user === null || ! $user->is_active || ! Hash::check($password, $user->password)) {
            return null;
        }

        return ['user' => $user, 'token' => $this->issueToken($user)];
    }

    /**
     * Создаёт организацию и её первого пользователя, сразу выдаёт токен.
     *
     * @return array{user: User, token: string}
     */
    public function register(string $organizationName, string $name, string $email, string $password): array
    {
        return Organization::query()->getConnection()->transaction(function () use ($organizationName, $name, $email, $password): array {
            $organization = Organization::query()->create([
                'name' => trim($organizationName),
                'slug' => $this->uniqueSlug($organizationName),
                'is_active' => true,
            ]);

            $user = User::query()->create([
                'organization_id' => $organization->id,
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'password' => $password,
                'is_active' => true,
            ]);

            return ['user' => $user, 'token' => $this->issueToken($user)];
        });
    }

    private function issueToken(User $user): string
    {
        $plain = self::PREFIX.Str::random(48);

        $user->tokens()->create([
            'token_hash' => $this->hash($plain),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return $plain;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    public function authenticate(string $plain): ?User
    {
        $token = UserToken::query()
            ->with('user.organization')
            ->where('token_hash', $this->hash($plain))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        $user = $token?->user;

        if ($user === null || ! $user->is_active || ! $user->organization?->is_active) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $user;
    }

    public function logout(string $plain): void
    {
        UserToken::query()->where('token_hash', $this->hash($plain))->delete();
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
