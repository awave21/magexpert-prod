<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // исходящие письма сайта: запись создаётся вместе с действием пользователя, отправка идёт из очереди с повторами
        Schema::create('mail_outbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('template');
            $table->string('fallback_template')->nullable();
            $table->string('to_email')->index();
            $table->json('data')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('sender_message_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_outbox');
    }
};
