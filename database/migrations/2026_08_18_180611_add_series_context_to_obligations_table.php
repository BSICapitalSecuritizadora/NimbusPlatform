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
        Schema::table('obligations', function (Blueprint $table) {
            $table->foreignId('obligation_series_id')->nullable()->after('emission_id')->constrained()->nullOnDelete();
            $table->foreignId('obligation_series_rule_id')->nullable()->after('obligation_series_id')->constrained()->nullOnDelete();
            $table->date('competence_date')->nullable()->after('obligation_series_rule_id');
            $table->string('generation_source')->nullable()->after('competence_date');
            $table->timestamp('generated_at')->nullable()->after('generation_source');

            $table->unique(['obligation_series_id', 'competence_date'], 'obligations_series_competence_unique');
            $table->index(['obligation_series_id', 'status'], 'obligations_series_status_index');
            $table->index('competence_date', 'obligations_competence_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('obligations', function (Blueprint $table) {
            $table->dropUnique('obligations_series_competence_unique');
            $table->dropIndex('obligations_series_status_index');
            $table->dropIndex('obligations_competence_date_index');
            $table->dropConstrainedForeignId('obligation_series_rule_id');
            $table->dropConstrainedForeignId('obligation_series_id');
            $table->dropColumn(['competence_date', 'generation_source', 'generated_at']);
        });
    }
};
