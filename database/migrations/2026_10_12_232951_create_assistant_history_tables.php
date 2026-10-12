<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История ИИ-помощника для админки: какие вопросы задают и какие документы библиотеки открывают.
 * Записи старше services.assistant.history_months удаляются по расписанию (model:prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->boolean('found');
            $table->jsonb('sources');
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms');
            $table->timestamps();

            $table->index(['created_at', 'found']);
        });

        Schema::create('library_document_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_library_id')->constrained('medical_libraries')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assistant_question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);
            $table->unsignedInteger('page')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['medical_library_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_document_opens');
        Schema::dropIfExists('assistant_questions');
    }
};
