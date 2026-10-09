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
        Schema::table('sender_addresses', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable()->after('name');
            $table->string('confirmation_token', 64)->nullable()->unique()->after('confirmed_at');
            $table->timestamp('confirmation_sent_at')->nullable()->after('confirmation_token');
        });
    }

    public function down(): void
    {
        Schema::table('sender_addresses', function (Blueprint $table): void {
            $table->dropUnique(['confirmation_token']);
            $table->dropColumn(['confirmed_at', 'confirmation_token', 'confirmation_sent_at']);
        });
    }
};
