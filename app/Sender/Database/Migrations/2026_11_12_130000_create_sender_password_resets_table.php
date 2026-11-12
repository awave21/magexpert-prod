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
        Schema::create('sender_password_resets', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('sender_users')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_password_resets');
    }
};
