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
        // база, которая повторяет подписчиков из приложения (например, пользователей сайта с согласием на рассылку)
        Schema::table('sender_lists', function (Blueprint $table): void {
            $table->string('source', 20)->nullable()->after('description');
            $table->timestamp('synced_at')->nullable()->after('source');
        });

        // сколько писем в сутки может отправить организация; пусто — без ограничения
        Schema::table('sender_organizations', function (Blueprint $table): void {
            $table->unsignedInteger('daily_limit')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('sender_organizations', fn (Blueprint $table) => $table->dropColumn('daily_limit'));
        Schema::table('sender_lists', fn (Blueprint $table) => $table->dropColumn(['source', 'synced_at']));
    }
};
