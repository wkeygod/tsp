<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_action_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action_type', 120);
            $table->string('target_type', 80);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->json('context')->nullable();
            $table->dateTime('performed_at');
            $table->timestamps();

            $table->index(['target_type', 'target_id', 'performed_at']);
            $table->index(['action_type', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_action_logs');
    }
};
