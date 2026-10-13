<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Теги мероприятия для рассылок: задаются в форме мероприятия и попадают контакту в Sender при регистрации.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->json('tags')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('tags'));
    }
};
