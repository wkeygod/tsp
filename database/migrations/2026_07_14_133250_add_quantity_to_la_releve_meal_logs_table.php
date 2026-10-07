<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('la_releve_meal_logs', function (Blueprint $table): void {
            // Default 1 preserves existing rows and La Relève's toggle flow
            // (always one meal per row) exactly as-is; only the Extra kiosk
            // flow exposes a way to record a quantity other than 1.
            $table->unsignedSmallInteger('quantity')->default(1)->after('meal_type');
        });
    }

    public function down(): void
    {
        Schema::table('la_releve_meal_logs', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });
    }
};
