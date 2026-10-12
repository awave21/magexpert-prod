<?php

namespace App\Sender\Console;

use App\Sender\Models\Organization;
use App\Sender\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Назначение владельца вручную: для организаций, оставшихся без владельца, или когда владелец недоступен.
 * Прежний владелец становится администратором.
 */
class AssignOwnerCommand extends Command
{
    protected $signature = 'sender:assign-owner {organization_slug : slug организации} {email : email активного участника этой организации}';

    protected $description = 'Назначает владельца организации Sender (прежний владелец становится администратором)';

    public function handle(): int
    {
        $slug = (string) $this->argument('organization_slug');
        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null) {
            $this->error("Организация '{$slug}' не найдена");

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));

        $result = $organization->getConnection()->transaction(function () use ($organization, $email): string {
            $users = $organization->users()->where(fn ($query) => $query->where('email', $email)->orWhere('role', User::ROLE_OWNER))
                ->orderBy('id')->lockForUpdate()->get();
            $target = $users->firstWhere('email', $email);

            if ($target === null || ! $target->is_active) {
                return 'not_found';
            }

            if ($target->isOwner()) {
                return 'already';
            }

            $users->filter(fn (User $user): bool => $user->isOwner())
                ->each(fn (User $user) => $user->forceFill(['role' => User::ROLE_ADMIN])->save());
            $target->forceFill(['role' => User::ROLE_OWNER])->save();

            return 'assigned';
        });

        return match ($result) {
            'not_found' => $this->failed("В организации '{$slug}' нет активного пользователя {$email}"),
            'already' => $this->succeeded("{$email} уже владелец организации '{$slug}'"),
            default => $this->succeeded("Владелец организации '{$slug}' теперь {$email}"),
        };
    }

    private function failed(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    private function succeeded(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }
}
