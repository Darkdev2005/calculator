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
        Schema::create('calculation_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 16);
            $table->decimal('operand_left', 20, 8);
            $table->decimal('operand_right', 20, 8);
            $table->decimal('result', 20, 8);
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->index(['user_id', 'calculated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calculation_histories');
    }
};
