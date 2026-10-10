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
        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->string('smtp_queue_id', 32)->nullable()->index()->after('attempts');
            $table->boolean('tracked')->default(false)->after('smtp_queue_id');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('opened_at')->nullable()->after('delivered_at');
            $table->timestamp('clicked_at')->nullable()->after('opened_at');
            $table->unsignedInteger('opens_count')->default(0)->after('clicked_at');
            $table->unsignedInteger('clicks_count')->default(0)->after('opens_count');
        });

        // что происходило с письмом после отправки: доставка, отказ, открытия, клики
        Schema::create('sender_message_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('sender_messages')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('detail')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('is_auto')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['message_id', 'type']);
        });

        Schema::table('sender_campaigns', function (Blueprint $table): void {
            $table->boolean('track')->default(true)->after('list_id');
        });

        Schema::table('sender_contacts', function (Blueprint $table): void {
            $table->timestamp('last_opened_at')->nullable()->after('checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('sender_contacts', fn (Blueprint $table) => $table->dropColumn('last_opened_at'));
        Schema::table('sender_campaigns', fn (Blueprint $table) => $table->dropColumn('track'));
        Schema::dropIfExists('sender_message_events');
        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->dropIndex(['smtp_queue_id']);
            $table->dropColumn(['smtp_queue_id', 'tracked', 'delivered_at', 'opened_at', 'clicked_at', 'opens_count', 'clicks_count']);
        });
    }
};
