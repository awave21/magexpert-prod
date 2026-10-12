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
        // сколько адресов рассылки не прошли проверку перед отправкой и не получат письмо
        Schema::table('sender_campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('rejected_count')->default(0)->after('recipients_count');
        });
    }

    public function down(): void
    {
        Schema::table('sender_campaigns', function (Blueprint $table): void {
            $table->dropColumn('rejected_count');
        });
    }
};
