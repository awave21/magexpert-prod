<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('sender.connection');
    }

    /**
     * Подтверждена ли почта пользователя: только к такому аккаунту вход через Яндекс привязывается по email.
     * Уже созданные пользователи считаются подтверждёнными, чтобы их вход через Яндекс продолжил работать.
     */
    public function up(): void
    {
        Schema::table('sender_users', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        DB::connection($this->getConnection())->table('sender_users')->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('sender_users', fn (Blueprint $table) => $table->dropColumn('email_verified_at'));
    }
};
