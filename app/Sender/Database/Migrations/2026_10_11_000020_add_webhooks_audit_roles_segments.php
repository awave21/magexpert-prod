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
        // куда сообщать о доставке, отказах, жалобах, отписках, открытиях и кликах
        Schema::create('sender_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('secret', 64);
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        // кто что сделал в админке: запуск и отмена рассылок, удаление баз, ключи, домены
        Schema::create('sender_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('sender_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('sender_users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject', 200)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('sender_users', function (Blueprint $table): void {
            $table->string('role', 10)->default('admin')->after('email');
        });

        Schema::table('sender_campaigns', function (Blueprint $table): void {
            $table->json('segment')->nullable()->after('list_id');
        });
    }

    public function down(): void
    {
        Schema::table('sender_campaigns', fn (Blueprint $table) => $table->dropColumn('segment'));
        Schema::table('sender_users', fn (Blueprint $table) => $table->dropColumn('role'));
        Schema::dropIfExists('sender_audit_logs');
        Schema::dropIfExists('sender_webhooks');
    }
};
