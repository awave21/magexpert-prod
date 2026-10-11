<?php

namespace App\Sender\Console;

use App\Sender\Models\Organization;
use App\Sender\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class UserCreateCommand extends Command
{
    protected $signature = 'sender:user-create {email} {--name= : имя} {--organization= : slug организации} {--password= : пароль (если не указан, будет сгенерирован)} {--role=admin : admin, editor или viewer}';

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
            $this->error('Роль: admin (всё), editor (письма, базы, рассылки) или viewer (только просмотр)');

            return self::FAILURE;
        }

        $email = Str::lower((string) $this->argument('email'));
        $password = $this->option('password') ?: Str::random(14);

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'organization_id' => $organization->id,
                'name' => $this->option('name') ?: $email,
                'role' => $role,
                'password' => $password,
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
