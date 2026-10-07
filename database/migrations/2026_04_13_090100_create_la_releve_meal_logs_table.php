<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('la_releve_meal_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('la_releve_entry_id')->constrained('la_releve_entries')->cascadeOnDelete();
            $table->string('meal_type', 50);
            $table->dateTime('consumed_at');
            $table->foreignId('meal_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['la_releve_entry_id', 'consumed_at']);
            $table->index('meal_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('la_releve_meal_logs');
    }
};
