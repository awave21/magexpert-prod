<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Роли владельца (owner) и дизайнера (designer). Колонка role уже строковая, схема не меняется:
 * в каждой организации владельцем становится самый ранний активный администратор, а если его нет —
 * самый ранний активный пользователь с любой ролью. Организации без активных пользователей остаются без владельца.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('sender.connection');
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());

        $organizations = $db->table('sender_users')->distinct()->pluck('organization_id');

        foreach ($organizations as $organizationId) {
            $hasOwner = $db->table('sender_users')->where('organization_id', $organizationId)->where('role', 'owner')->exists();

            if ($hasOwner) {
                continue;
            }

            $earliest = fn (?string $role) => $db->table('sender_users')
                ->where('organization_id', $organizationId)
                ->when($role !== null, fn ($query) => $query->where('role', $role))
                ->where('is_active', true)
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('id');

            $first = $earliest('admin');

            if ($first === null) {
                $first = $earliest(null);
                $this->warn($first === null
                    ? "Sender: в организации #{$organizationId} нет активных пользователей, владелец не назначен. Назначьте его: php artisan sender:assign-owner"
                    : "Sender: в организации #{$organizationId} нет активного администратора, владельцем стал самый ранний активный пользователь #{$first}");
            }

            if ($first !== null) {
                $db->table('sender_users')->where('id', $first)->update(['role' => 'owner']);
            }
        }
    }

    /**
     * Предупреждение в журнал и, при запуске из консоли, в вывод migrate.
     */
    private function warn(string $message): void
    {
        Log::warning($message);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDOUT, "  {$message}\n");
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        $db->table('sender_users')->where('role', 'owner')->update(['role' => 'admin']);
        $db->table('sender_users')->where('role', 'designer')->update(['role' => 'viewer']);
    }
};
