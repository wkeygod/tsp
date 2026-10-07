<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meal_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meal_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('card_uid')->nullable();
            $table->dateTime('logged_at');
            $table->enum('status', ['approved', 'rejected']);
            $table->decimal('amount_charged', 10, 2)->default(0);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'logged_at']);
            $table->index(['card_uid', 'logged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_logs');
    }
};
