<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('la_releve_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name', 160);
            $table->string('external_id', 100);
            $table->date('entry_date');
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('allowed_meals_count')->default(1);
            $table->json('allowed_meal_types')->nullable();
            $table->string('department', 120)->nullable();
            $table->string('source', 120)->nullable();
            $table->string('category', 120)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('entry_date');
            $table->index('full_name');
            $table->index('external_id');
            $table->unique(['entry_date', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('la_releve_entries');
    }
};
