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
        Schema::create('sender_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('sender_domains')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('sender_templates')->nullOnDelete();
            $table->string('to_email')->index();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject');
            $table->string('status')->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_messages');
    }
};
