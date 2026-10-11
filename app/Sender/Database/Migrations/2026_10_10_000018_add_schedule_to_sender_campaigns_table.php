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
        // как отправлять рассылку: сразу или пачками по расписанию
        Schema::table('sender_campaigns', function (Blueprint $table): void {
            $table->string('send_mode', 10)->default('now')->after('track');
            $table->unsignedInteger('batch_size')->nullable()->after('send_mode');
            $table->unsignedSmallInteger('batch_interval')->nullable()->after('batch_size');
            $table->unsignedInteger('daily_limit')->nullable()->after('batch_interval');
            $table->string('window_start', 5)->nullable()->after('daily_limit');
            $table->string('window_end', 5)->nullable()->after('window_start');
            $table->json('window_days')->nullable()->after('window_end');
            $table->timestamp('scheduled_at')->nullable()->after('window_days');
            $table->timestamp('prepared_at')->nullable()->after('started_at');
            $table->timestamp('next_batch_at')->nullable()->after('prepared_at');
            $table->timestamp('paused_at')->nullable()->after('next_batch_at');
            $table->timestamp('cancelled_at')->nullable()->after('paused_at');
        });

        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->timestamp('dispatched_at')->nullable()->after('sent_at');

            // один адрес получает письмо рассылки один раз, даже если подготовка запустилась повторно
            $table->unique(['campaign_id', 'to_email']);
            $table->index(['campaign_id', 'status']);
            $table->index('dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->dropUnique(['campaign_id', 'to_email']);
            $table->dropIndex(['campaign_id', 'status']);
            $table->dropIndex(['dispatched_at']);
            $table->dropColumn('dispatched_at');
        });

        Schema::table('sender_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['send_mode', 'batch_size', 'batch_interval', 'daily_limit', 'window_start', 'window_end', 'window_days', 'scheduled_at', 'prepared_at', 'next_batch_at', 'paused_at', 'cancelled_at']);
        });
    }
};
