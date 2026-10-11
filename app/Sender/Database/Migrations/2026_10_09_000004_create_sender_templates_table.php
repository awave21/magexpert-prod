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
        Schema::create('sender_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('subject');
            $table->longText('body_html');
            $table->longText('body_text')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_templates');
    }
};
