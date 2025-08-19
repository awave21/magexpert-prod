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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // Название события
            $table->string('slug')->unique(); // URL-slug
            $table->date('start_date')->nullable(); // Дата начала (может быть пустой)
            $table->time('start_time')->nullable(); // Время начала (может быть пустым)
            $table->date('end_date')->nullable(); // Дата окончания (может быть пустой)
            $table->time('end_time')->nullable(); // Время окончания (может быть пустым)
            $table->string('event_type')->nullable(); // Тип события
            $table->text('short_description')->nullable(); // Краткое описание
            $table->text('full_description')->nullable(); // Полное описание
            $table->string('topic')->nullable(); // О чем событие
            $table->string('location')->nullable(); // Место проведения
            $table->enum('format', ['online', 'offline', 'hybrid'])->default('offline'); // Формат проведения
            $table->string('image')->nullable(); // Изображение заставка
            $table->boolean('registration_enabled')->default(true); // Регистрация включена/выключена
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete(); // Связь с категорией
            $table->boolean('is_active')->default(true); // Показать или скрыть
            $table->integer('sort_order')->default(0); // Сортировка
            $table->boolean('is_archived')->default(false); // Архив (да/нет)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
