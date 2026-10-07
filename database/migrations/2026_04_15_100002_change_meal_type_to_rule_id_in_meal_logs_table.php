<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('meal_type')->nullable()->change();
        });
        if (!Schema::hasIndex('meal_logs', 'meal_logs_meal_type_idx')) {
            Schema::table('meal_logs', function (Blueprint $table) {
                $table->index('meal_type', 'meal_logs_meal_type_idx');
            });
        }
    }
    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->enum('meal_type', ['breakfast', 'lunch', 'dinner'])->default('lunch')->change();
        });
    }
};
