<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->enum('meal_type', ['breakfast', 'lunch', 'dinner'])
                ->default('lunch')
                ->after('logged_at')
                ->comment('Type de repas consomme');
        });
    }

    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->dropColumn('meal_type');
        });
    }
};
