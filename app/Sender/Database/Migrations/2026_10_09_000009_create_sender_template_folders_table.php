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
        Schema::create('sender_template_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::table('sender_templates', function (Blueprint $table): void {
            $table->foreignId('folder_id')->nullable()->after('organization_id')
                ->constrained('sender_template_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sender_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('sender_template_folders');
    }
};
