<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сегменты: сохранённые условия отбора внутри одной базы. В рассылке выбираются наравне с базой.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('sender.connection');
    }

    public function up(): void
    {
        Schema::create('sender_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('sender_lists')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            $table->jsonb('conditions');
            $table->timestamps();

            $table->index(['organization_id', 'name']);
        });

        Schema::table('sender_campaigns', function (Blueprint $table): void {
            $table->foreignId('segment_id')->nullable()->after('list_id')->constrained('sender_segments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sender_campaigns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('segment_id');
        });

        Schema::dropIfExists('sender_segments');
    }
};
