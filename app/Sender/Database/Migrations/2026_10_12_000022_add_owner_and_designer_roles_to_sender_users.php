<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Роли владельца (owner) и дизайнера (designer). Колонка role уже строковая, схема не меняется:
 * в каждой организации владельцем становится самый ранний активный администратор.
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

            $first = $db->table('sender_users')
                ->where('organization_id', $organizationId)
                ->where('role', 'admin')
                ->where('is_active', true)
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('id');

            if ($first !== null) {
                $db->table('sender_users')->where('id', $first)->update(['role' => 'owner']);
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        $db->table('sender_users')->where('role', 'owner')->update(['role' => 'admin']);
        $db->table('sender_users')->where('role', 'designer')->update(['role' => 'viewer']);
    }
};
