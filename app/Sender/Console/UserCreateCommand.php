<?php

namespace App\Sender\Console;

use App\Sender\Models\Organization;
use App\Sender\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class UserCreateCommand extends Command
{
    protected $signature = 'sender:user-create {email} {--name= : имя} {--organization= : slug организации} {--password= : пароль (если не указан, будет сгенерирован)} {--role=admin : owner, admin, editor, designer или viewer (первый admin без владельца становится владельцем)}';

    protected $description = 'Создаёт пользователя для входа в интерфейс Sender (повторный запуск обновляет пароль)';

    public function handle(): int
    {
        $slug = $this->option('organization') ?: config('sender.client.organization');
        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null) {
            $this->error("Организация '{$slug}' не найдена. Сначала выполните sender:install-defaults");

            return self::FAILURE;
        }

        $role = (string) $this->option('role');

        if (! in_array($role, User::ROLES, true)) {
            $this->error('Роль: owner (владелец), admin (всё), editor (письма, базы, рассылки), designer (только письма) или viewer (только просмотр)');

            return self::FAILURE;
        }

        $email = Str::lower((string) $this->argument('email'));
        $password = $this->option('password') ?: Str::random(14);
        $existing = User::query()->where('email', $email)->first();
        $owner = $organization->owner()->first();

        if ($existing?->isOwner() && (int) $existing->organization_id !== (int) $organization->id) {
            $this->error("Пользователь {$email} — владелец другой организации. Сначала передайте владение в её настройках");

            return self::FAILURE;
        }

        if ($role === User::ROLE_OWNER && $owner !== null && $owner->email !== $email) {
            $this->error("У организации уже есть владелец ({$owner->email}). Владение передаётся в настройках организации");

            return self::FAILURE;
        }

        if ($existing?->isOwner() && $existing->organization_id === $organization->id && $role !== User::ROLE_OWNER) {
            $this->warn('Пользователь — владелец организации, роль не меняется');
            $role = User::ROLE_OWNER;
        } elseif ($role === User::ROLE_ADMIN && $owner === null) {
            $this->line('У организации не было владельца: им становится этот администратор');
            $role = User::ROLE_OWNER;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'organization_id' => $organization->id,
                'name' => $this->option('name') ?: $email,
                'role' => $role,
                'password' => $password,
                'password_set' => true,
                'is_active' => true,
            ],
        );

        $this->info("Пользователь {$email} готов (организация {$slug}).");

        if (! $this->option('password')) {
            $this->line("Пароль: {$password}");
        }

        return self::SUCCESS;
    }
}
