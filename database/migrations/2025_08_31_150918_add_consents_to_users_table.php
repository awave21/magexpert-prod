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
            //чекбоксы
            $table->boolean('privacy_consent')->default(false);   // Обязательный
            $table->boolean('oferta_consent')->default(false);  // Обязательный
            $table->boolean('newsletter_consent')->default(false); // Необязательный
        });
       
    }

    /**
     * Reverse the migrations.
     */
 public function down(): void
{
    if (Schema::hasColumn('users', 'privacy_consent')) {
        Schema::table('users', function (Blueprint $table) {
            //чекбоксы
            $table->dropColumn([
                'privacy_consent',
                'oferta_consent',
                'newsletter_consent',
            ]);
        });
    }
}

};
