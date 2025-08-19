<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Для PostgreSQL используем прямые SQL запросы для изменения enum
        DB::statement("ALTER TABLE event_user DROP CONSTRAINT IF EXISTS event_user_access_type_check");
        DB::statement("ALTER TABLE event_user ADD CONSTRAINT event_user_access_type_check CHECK (access_type IN ('free', 'paid', 'promotional', 'admin', 'invited'))");
        
        DB::statement("ALTER TABLE event_user DROP CONSTRAINT IF EXISTS event_user_payment_status_check");
        DB::statement("ALTER TABLE event_user ADD CONSTRAINT event_user_payment_status_check CHECK (payment_status IN ('free', 'pending', 'completed', 'failed', 'refunded'))");
        
        Schema::table('event_user', function (Blueprint $table) {
            // Делаем access_granted_at nullable
            $table->dateTime('access_granted_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Возвращаем старые constraints
        DB::statement("ALTER TABLE event_user DROP CONSTRAINT IF EXISTS event_user_access_type_check");
        DB::statement("ALTER TABLE event_user ADD CONSTRAINT event_user_access_type_check CHECK (access_type IN ('paid', 'free', 'invited'))");
        
        DB::statement("ALTER TABLE event_user DROP CONSTRAINT IF EXISTS event_user_payment_status_check");
        DB::statement("ALTER TABLE event_user ADD CONSTRAINT event_user_payment_status_check CHECK (payment_status IN ('pending', 'completed', 'failed', 'refunded'))");
        
        Schema::table('event_user', function (Blueprint $table) {
            // Возвращаем access_granted_at как NOT NULL
            $table->dateTime('access_granted_at')->change();
        });
    }
};