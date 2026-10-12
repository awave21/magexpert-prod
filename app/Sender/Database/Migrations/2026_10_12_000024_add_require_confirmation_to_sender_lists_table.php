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
     * Новые подписчики базы, добавленные через API без согласия, сначала получают письмо подтверждения подписки.
     * По умолчанию подтверждение не требуется: базы работают, как и раньше.
     */
    public function up(): void
    {
        Schema::table('sender_lists', function (Blueprint $table): void {
            $table->boolean('require_confirmation')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('sender_lists', fn (Blueprint $table) => $table->dropColumn('require_confirmation'));
    }
};
