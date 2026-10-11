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
     * Вход в Sender через Яндекс ID и VK ID.
     */
    public function up(): void
    {
        Schema::create('sender_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('sender_users')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id');
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_social_accounts');
    }
};
