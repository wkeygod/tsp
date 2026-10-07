<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (!Schema::hasColumn('employees', 'position')) {
                $table->string('position', 120)->nullable()->after('last_name');
            }

            if (!Schema::hasColumn('employees', 'rig_company')) {
                $table->string('rig_company', 120)->nullable()->after('position');
            }
        });

        DB::table('employees')
            ->whereNull('position')
            ->update(['position' => DB::raw("coalesce(department, '')")]);

        DB::table('employees')
            ->whereNull('rig_company')
            ->update(['rig_company' => '']);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'rig_company')) {
                $table->dropColumn('rig_company');
            }

            if (Schema::hasColumn('employees', 'position')) {
                $table->dropColumn('position');
            }
        });
    }
};
