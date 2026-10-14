<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Название сегмента в рассылке: после удаления сегмента отчёт показывает, какой части базы она ушла,
 * а не выглядит как рассылка всей базе.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('sender.connection');
    }

    public function up(): void
    {
        Schema::table('sender_campaigns', function (Blueprint $table): void {
            $table->string('segment_name', 200)->nullable()->after('segment_id');
        });
    }

    public function down(): void
    {
        Schema::table('sender_campaigns', fn (Blueprint $table) => $table->dropColumn('segment_name'));
    }
};
