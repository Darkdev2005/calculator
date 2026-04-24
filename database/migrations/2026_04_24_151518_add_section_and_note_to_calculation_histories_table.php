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
        Schema::table('calculation_histories', function (Blueprint $table) {
            $table->foreignId('calculation_section_id')
                ->nullable()
                ->after('user_id')
                ->constrained('calculation_sections')
                ->nullOnDelete();
            $table->string('note', 255)->nullable()->after('result');

            $table->index(['user_id', 'calculation_section_id'], 'calc_histories_user_section_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calculation_histories', function (Blueprint $table) {
            $table->dropIndex('calc_histories_user_section_idx');
            $table->dropConstrainedForeignId('calculation_section_id');
            $table->dropColumn('note');
        });
    }
};
