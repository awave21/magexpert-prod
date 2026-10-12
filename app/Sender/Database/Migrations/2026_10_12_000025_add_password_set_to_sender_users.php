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
     * Задан ли пароль самим пользователем. У зарегистрированных через Яндекс или ВКонтакте пароль случайный:
     * они задают его в профиле без текущего пароля, а до этого не могут передать владение.
     */
    public function up(): void
    {
        Schema::table('sender_users', function (Blueprint $table): void {
            $table->boolean('password_set')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('sender_users', fn (Blueprint $table) => $table->dropColumn('password_set'));
    }
};
