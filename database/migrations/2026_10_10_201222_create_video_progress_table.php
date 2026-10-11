<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * На какой секунде врач остановился в записи мероприятия: одна строка на пару «пользователь — видео».
     * video_key — ID видео в Кинескопе (для плейлиста — ID конкретного ролика в нём).
     */
    public function up(): void
    {
        Schema::create('video_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('video_key', 64);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('duration')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_id', 'video_key']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_progress');
    }
};
