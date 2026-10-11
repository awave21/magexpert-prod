<?php

namespace App\Sender\Services;

use App\Sender\Models\ApiKey;
use App\Sender\Models\Organization;
use Illuminate\Support\Str;

class ApiKeyService
{
    private const PREFIX = 'mxs_';

    /**
     * Создаёт ключ. Открытое значение возвращается один раз, в базе хранится только хеш.
     *
     * @return array{key: ApiKey, plain: string}
     */
    public function create(Organization $organization, string $name): array
    {
        $plain = self::PREFIX.Str::random(40);

        $key = $organization->apiKeys()->create([
            'name' => $name,
            'key_prefix' => substr($plain, 0, 8),
            'key_hash' => $this->hash($plain),
        ]);

        return ['key' => $key, 'plain' => $plain];
    }

    public function authenticate(string $plain): ?ApiKey
    {
        $key = ApiKey::query()
            ->where('key_hash', $this->hash($plain))
            ->whereNull('revoked_at')
            ->first();

        if ($key === null || ! $key->organization?->is_active) {
            return null;
        }

        $key->forceFill(['last_used_at' => now()])->save();

        return $key;
    }

    public function revoke(ApiKey $key): void
    {
        $key->forceFill(['revoked_at' => now()])->save();
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
