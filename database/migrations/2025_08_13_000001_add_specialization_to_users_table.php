<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
	public function up(): void
	{
		Schema::table('users', function (Blueprint $table) {
			$table->string('specialization')->nullable()->after('position');
		});

		// Переносим старые значения из company в specialization, если они есть
		DB::statement("UPDATE users SET specialization = company WHERE specialization IS NULL");
	}

	public function down(): void
	{
		Schema::table('users', function (Blueprint $table) {
			$table->dropColumn('specialization');
		});
	}
};


