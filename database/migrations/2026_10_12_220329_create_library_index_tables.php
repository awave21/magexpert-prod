<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Поиск ИИ-помощника по «Медицинской библиотеке»: фрагменты документов с векторами (pgvector)
 * и полнотекстовым индексом на русском и английском для гибридного поиска.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('library_indexes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_library_id')->unique()->constrained('medical_libraries')->cascadeOnDelete();
            $table->string('file_sha256', 64);
            $table->string('parser', 32);
            $table->string('parser_version', 32)->nullable();
            $table->string('embedding_model', 64);
            $table->string('language', 8);
            $table->unsignedInteger('pages');
            $table->unsignedInteger('chunks_count');
            $table->timestamp('indexed_at');
            $table->timestamps();
        });

        Schema::create('library_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_library_id')->constrained('medical_libraries')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('type', 16);
            $table->unsignedInteger('page_start');
            $table->unsignedInteger('page_end');
            $table->json('headings');
            $table->text('content');
            $table->text('embed_text');
            $table->string('content_sha256', 64)->index();
            $table->unsignedInteger('tokens');
            $table->vector('embedding', dimensions: 1536)->index();
            $table->timestamps();

            $table->unique(['medical_library_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE library_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (
                to_tsvector('russian', embed_text) || to_tsvector('english', embed_text)
            ) STORED
        SQL);
        DB::statement('CREATE INDEX library_chunks_search_index ON library_chunks USING gin (search)');
    }

    public function down(): void
    {
        Schema::dropIfExists('library_chunks');
        Schema::dropIfExists('library_indexes');
    }
};
