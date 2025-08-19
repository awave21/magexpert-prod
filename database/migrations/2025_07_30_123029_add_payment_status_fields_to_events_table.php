<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Явное указание платности мероприятия (независимо от цены)
            $table->boolean('is_paid')->default(false)->after('price')->comment('Платное мероприятие (да/нет)');
            
            // Показывать ли цену на фронтенде
            $table->boolean('show_price')->default(true)->after('is_paid')->comment('Показывать цену на фронтенде');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['is_paid', 'show_price']);
        });
    }
};
