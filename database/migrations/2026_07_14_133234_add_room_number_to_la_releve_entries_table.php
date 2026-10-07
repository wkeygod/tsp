<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('la_releve_entries', function (Blueprint $table): void {
            $table->string('room_number', 50)->nullable()->after('rig_company');
        });
    }

    public function down(): void
    {
        Schema::table('la_releve_entries', function (Blueprint $table): void {
            $table->dropColumn('room_number');
        });
    }
};
