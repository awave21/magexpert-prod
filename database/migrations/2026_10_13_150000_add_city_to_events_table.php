<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Город мероприятия: уходит в письмо о регистрации переменной event_city.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('city', 120)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('city'));
    }
};
