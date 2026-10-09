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
            $table->string('editor', 16)->default('html')->after('body_text');
            $table->json('design')->nullable()->after('editor');
        });
    }

    public function down(): void
    {
        Schema::table('sender_templates', function (Blueprint $table): void {
            $table->dropColumn(['editor', 'design']);
        });
    }
};
