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
        Schema::table('sender_templates', function (Blueprint $table): void {
            $table->foreignId('sender_address_id')->nullable()->after('subject')
                ->constrained('sender_addresses')->nullOnDelete();
            $table->string('reply_to')->nullable()->after('sender_address_id');
            $table->string('preheader')->nullable()->after('reply_to');
        });

        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->string('reply_to')->nullable()->after('from_name');
        });
    }

    public function down(): void
    {
        Schema::table('sender_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sender_address_id');
            $table->dropColumn(['reply_to', 'preheader']);
        });

        Schema::table('sender_messages', function (Blueprint $table): void {
            $table->dropColumn('reply_to');
        });
    }
};
