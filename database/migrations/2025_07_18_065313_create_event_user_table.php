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
        Schema::create('event_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('access_type', ['paid', 'free', 'invited'])->default('paid'); // Тип доступа
            $table->decimal('payment_amount', 10, 2)->nullable(); // Сумма оплаты
            $table->string('payment_id')->nullable(); // ID платежа
            $table->enum('payment_status', ['pending', 'completed', 'failed', 'refunded'])->default('pending'); // Статус платежа
            $table->dateTime('access_granted_at'); // Когда был предоставлен доступ
            $table->dateTime('access_expires_at')->nullable(); // Когда истекает доступ (null - бессрочный)
            $table->boolean('is_active')->default(true); // Активен ли доступ
            $table->timestamps();
            
            // Предотвращение дублирования связей
            $table->unique(['event_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_user');
    }
};
