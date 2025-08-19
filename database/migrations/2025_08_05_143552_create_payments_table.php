<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('RUB');
            $table->string('status')->default('pending'); // pending, processing, completed, failed, cancelled, refunded
            $table->string('payment_system')->default('test'); // test, yookassa, stripe, robokassa, sberbank
            $table->string('external_id')->nullable(); // ID в платежной системе
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // Дополнительные данные
            $table->json('callback_data')->nullable(); // Данные от callback платежной системы
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            // Индексы
            $table->index(['user_id', 'event_id']);
            $table->index('status');
            $table->index('payment_system');
            $table->index('external_id');
            $table->index('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
