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
        // базы подписчиков
        Schema::create('sender_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('sender_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('sender_lists')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['list_id', 'email']);
            $table->index(['organization_id', 'email']);
        });

        Schema::create('sender_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->string('name', 200);
            $table->foreignId('template_id')->nullable()->constrained('sender_templates')->nullOnDelete();
            $table->foreignId('list_id')->nullable()->constrained('sender_lists')->nullOnDelete();
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->foreignId('campaign_id')->nullable()->after('template_id')
                ->constrained('sender_campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::dropIfExists('sender_campaigns');
        Schema::dropIfExists('sender_contacts');
        Schema::dropIfExists('sender_lists');
    }
};
