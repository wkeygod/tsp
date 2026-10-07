<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('ip_address', 45)->nullable()->after('name');
            $table->string('status', 30)->default('active')->after('is_active');
            $table->text('notes')->nullable()->after('last_seen_at');

            $table->index('status');
            $table->index('location');
        });

        DB::table('devices')->where('is_active', true)->update(['status' => 'active']);
        DB::table('devices')->where('is_active', false)->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['location']);
            $table->dropColumn(['ip_address', 'status', 'notes']);
        });
    }
};
