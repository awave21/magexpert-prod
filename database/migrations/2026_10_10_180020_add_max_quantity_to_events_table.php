<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Лимит мест на мероприятии. Поле давно есть в модели и форме, но миграции не было —
     * добавляем, только если столбца ещё нет (на части баз его могли создать вручную).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('events', 'max_quantity')) {
            Schema::table('events', function (Blueprint $table) {
                $table->unsignedInteger('max_quantity')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('events', 'max_quantity')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('max_quantity');
            });
        }
    }
};
