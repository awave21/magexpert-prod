<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Личные ключи для подключения ИИ-ассистентов к Sender по протоколу MCP.
 * Ключ принадлежит пользователю: ассистент действует с его ролью и в его организации.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('sender.connection');
    }

    public function up(): void
    {
        Schema::create('sender_mcp_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('sender_users')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_mcp_tokens');
    }
};
