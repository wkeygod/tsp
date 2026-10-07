<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->string('status', 30)->default('active')->after('is_active');
            $table->timestamp('blocked_at')->nullable()->after('status');
            $table->string('block_reason')->nullable()->after('blocked_at');
            $table->foreignId('replaced_by_card_id')->nullable()->after('employee_id')->constrained('cards')->nullOnDelete();
            $table->foreignId('assigned_employee_id')->nullable()->after('replaced_by_card_id')->constrained('employees')->nullOnDelete();
            $table->text('notes')->nullable()->after('block_reason');

            $table->index('status');
            $table->index('assigned_employee_id');
        });

        DB::table('cards')->where('is_active', true)->update(['status' => 'active']);
        DB::table('cards')->where('is_active', false)->update(['status' => 'inactive']);
        DB::table('cards')->update(['assigned_employee_id' => DB::raw('employee_id')]);
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['assigned_employee_id']);
            $table->dropConstrainedForeignId('assigned_employee_id');
            $table->dropConstrainedForeignId('replaced_by_card_id');
            $table->dropColumn(['status', 'blocked_at', 'block_reason', 'notes']);
        });
    }
};
