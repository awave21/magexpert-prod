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
        Schema::create('sender_variables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->text('default_value')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_variables');
    }
};
