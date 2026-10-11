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
        Schema::table('sender_contacts', function (Blueprint $table): void {
            $table->string('check_status', 20)->nullable()->after('data');
            $table->string('check_hint')->nullable()->after('check_status');
            $table->timestamp('checked_at')->nullable()->after('check_hint');

            $table->index(['list_id', 'check_status']);
        });
    }

    public function down(): void
    {
        Schema::table('sender_contacts', function (Blueprint $table): void {
            $table->dropIndex(['list_id', 'check_status']);
            $table->dropColumn(['check_status', 'check_hint', 'checked_at']);
        });
    }
};
