<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('sender.connection');
    }

    /**
     * Теги подписчика: массив коротких меток (например, event-63). Отбор по тегу идёт через GIN-индекс.
     */
    public function up(): void
    {
        Schema::table('sender_contacts', function (Blueprint $table): void {
            $table->jsonb('tags')->nullable()->after('data');
        });

        Schema::getConnection()->statement('CREATE INDEX sender_contacts_tags_gin ON sender_contacts USING GIN (tags jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::getConnection()->statement('DROP INDEX IF EXISTS sender_contacts_tags_gin');
        Schema::table('sender_contacts', fn (Blueprint $table) => $table->dropColumn('tags'));
    }
};
