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
        Schema::create('sender_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained('sender_domains')->cascadeOnDelete();
            $table->string('email');
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_addresses');
    }
};
