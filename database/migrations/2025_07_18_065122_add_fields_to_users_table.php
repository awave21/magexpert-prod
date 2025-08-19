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
        Schema::table('users', function (Blueprint $table) {
            // Разделяем имя на имя, фамилию и отчество
            $table->renameColumn('name', 'first_name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('middle_name')->nullable()->after('last_name');
            
            // Добавляем дополнительные поля
            $table->string('phone')->nullable()->after('email');
            $table->string('company')->nullable()->after('phone');
            $table->string('position')->nullable()->after('company');
            $table->string('avatar')->nullable()->after('position');
            $table->string('city')->nullable()->after('avatar');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Удаляем добавленные поля
            $table->dropColumn([
                'last_name',
                'middle_name',
                'phone',
                'company',
                'position',
                'avatar',
                'city'
            ]);
            
            // Возвращаем исходное название столбца
            $table->renameColumn('first_name', 'name');
        });
    }
};
