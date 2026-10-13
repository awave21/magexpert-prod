<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Когда пользователь сам отозвал согласие на рассылку (галочка в кабинете, отписка по ссылке из письма).
     * Такому пользователю согласие при регистрации на мероприятие автоматически не ставится.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('newsletter_withdrawn_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('newsletter_withdrawn_at'));
    }
};
