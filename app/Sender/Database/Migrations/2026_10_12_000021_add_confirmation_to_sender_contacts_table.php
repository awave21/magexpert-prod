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
     * Подтверждение подписки для адресов, загруженных без согласия: у такого подписчика есть ключ ссылки
     * (confirmation_token), но нет confirmed_at. Пока он не подтвердит подписку, рассылки ему не уходят.
     * У остальных подписчиков ключа нет: они получают рассылки, как и раньше.
     */
    public function up(): void
    {
        Schema::table('sender_contacts', function (Blueprint $table): void {
            $table->string('confirmation_token', 64)->nullable()->unique()->after('unsubscribed_at');
            $table->timestamp('confirmation_sent_at')->nullable()->after('confirmation_token');
            $table->timestamp('confirmed_at')->nullable()->after('confirmation_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('sender_contacts', function (Blueprint $table): void {
            $table->dropUnique(['confirmation_token']);
            $table->dropColumn(['confirmation_token', 'confirmation_sent_at', 'confirmed_at']);
        });
    }
};
