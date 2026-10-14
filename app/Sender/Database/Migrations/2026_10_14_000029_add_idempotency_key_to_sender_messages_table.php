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

    public function up(): void
    {
        // ключ идемпотентности из заголовка Idempotency-Key: повтор запроса с тем же ключом не создаёт второе письмо
        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->string('idempotency_key', 255)->nullable()->after('uuid');
            $table->unique(['organization_id', 'idempotency_key'], 'sender_messages_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->dropUnique('sender_messages_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
