<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table): void {
            $table->foreignId('device_id')->nullable()->after('meal_rule_id')->constrained()->nullOnDelete();
            $table->foreignId('processed_by_user_id')->nullable()->after('device_id')->constrained('users')->nullOnDelete();

            $table->index(['device_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table): void {
            $table->dropIndex(['device_id', 'logged_at']);
            $table->dropConstrainedForeignId('device_id');
            $table->dropConstrainedForeignId('processed_by_user_id');
        });
    }
};
