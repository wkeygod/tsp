<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->json('allowed_meal_types')
                ->nullable()
                ->after('rig_company')
                ->comment('Types de repas autorisés: breakfast, lunch, dinner');
        });
    }
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('allowed_meal_types');
        });
    }
};
