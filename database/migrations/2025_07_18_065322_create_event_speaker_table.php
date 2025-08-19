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
        Schema::create('event_speaker', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('speaker_id')->constrained()->cascadeOnDelete();
            $table->string('role')->nullable(); // Роль спикера на мероприятии (ведущий, докладчик и т.д.)
            $table->text('topic')->nullable(); // Тема выступления
            $table->integer('sort_order')->default(0); // Порядок отображения
            $table->timestamps();
            
            // Предотвращение дублирования связей
            $table->unique(['event_id', 'speaker_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_speaker');
    }
};
