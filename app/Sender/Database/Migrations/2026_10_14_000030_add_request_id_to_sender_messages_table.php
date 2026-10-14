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
        // идентификатор запроса клиента (X-Request-Id): по нему письмо находят по следу в логах сайта
        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->string('request_id', 64)->nullable()->after('idempotency_key')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->dropIndex(['request_id']);
            $table->dropColumn('request_id');
        });
    }
};
