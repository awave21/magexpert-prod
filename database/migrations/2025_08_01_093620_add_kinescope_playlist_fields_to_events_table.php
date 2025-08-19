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
            $table->string('kinescope_playlist_id')->nullable()->after('kinescope_id')->comment('ID плейлиста Кинескопа');
            $table->enum('kinescope_type', ['video', 'playlist'])->nullable()->after('kinescope_playlist_id')->comment('Тип контента Кинескопа');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['kinescope_playlist_id', 'kinescope_type']);
        });
    }
};
