<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * История сообщений, которые команда отправила в колокольчик кабинета.
     */
    public function up(): void
    {
        Schema::create('cabinet_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('url')->nullable();
            $table->string('audience', 16); // all | event | user
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_broadcasts');
    }
};
