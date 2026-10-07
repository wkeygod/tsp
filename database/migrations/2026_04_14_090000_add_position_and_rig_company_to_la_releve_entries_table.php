<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('la_releve_entries', function (Blueprint $table): void {
            if (!Schema::hasColumn('la_releve_entries', 'position')) {
                $table->string('position', 120)->nullable();
            }

            if (!Schema::hasColumn('la_releve_entries', 'rig_company')) {
                $table->string('rig_company', 120)->nullable();
            }
        });

        if (Schema::hasColumn('la_releve_entries', 'department')) {
            DB::table('la_releve_entries')
                ->whereNull('position')
                ->update(['position' => DB::raw("coalesce(department, '')")]);
        }

        if (Schema::hasColumn('la_releve_entries', 'source')) {
            DB::table('la_releve_entries')
                ->whereNull('rig_company')
                ->update(['rig_company' => DB::raw("coalesce(source, '')")]);
        }
    }

    public function down(): void
    {
        Schema::table('la_releve_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('la_releve_entries', 'position')) {
                $table->dropColumn('position');
            }

            if (Schema::hasColumn('la_releve_entries', 'rig_company')) {
                $table->dropColumn('rig_company');
            }
        });
    }
};
