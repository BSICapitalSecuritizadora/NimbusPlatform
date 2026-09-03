<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('emission_pu_external_benchmarks')) {
            Schema::create('emission_pu_external_benchmarks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('emission_id')->constrained()->restrictOnDelete();
                $table->string('source_type', 50);
                $table->string('source_name');
                $table->foreignId('source_document_id')->nullable()->constrained('documents')->restrictOnDelete();
                $table->foreignId('source_evidence_id')->nullable()->constrained('emission_pu_baseline_evidence')->restrictOnDelete();
                $table->date('reference_as_of');
                $table->string('input_file_name');
                $table->char('file_sha256', 64);
                $table->char('dataset_sha256', 64);
                $table->char('import_identity_sha256', 64)->unique();
                $table->unsignedInteger('row_count');
                $table->date('from_date');
                $table->date('to_date');
                $table->string('status', 20)->default('ready');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(
                    ['emission_id', 'status', 'reference_as_of'],
                    'pu_external_benchmark_lookup_index',
                );
                $table->index(
                    ['emission_id', 'dataset_sha256'],
                    'pu_external_benchmark_dataset_index',
                );
            });
        }

        if (! Schema::hasTable('emission_pu_external_benchmark_rows')) {
            Schema::create('emission_pu_external_benchmark_rows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('benchmark_id')->constrained('emission_pu_external_benchmarks')->restrictOnDelete();
                $table->date('reference_date');
                $table->string('unit_value', 64);
                $table->timestamps();

                $table->unique(
                    ['benchmark_id', 'reference_date'],
                    'pu_external_benchmark_date_unique',
                );
            });
        }

        if (! Schema::hasTable('emission_pu_external_validations')) {
            Schema::create('emission_pu_external_validations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('candidate_curve_version_id')
                    ->constrained('emission_pu_curve_versions', 'id', 'pu_external_validation_candidate_fk')
                    ->restrictOnDelete();
                $table->foreignId('benchmark_id')->constrained('emission_pu_external_benchmarks')->restrictOnDelete();
                $table->char('candidate_checksum', 64);
                $table->char('benchmark_dataset_sha256', 64);
                $table->string('comparison_algorithm_version', 64);
                $table->char('comparison_sha256', 64);
                $table->string('coverage_status', 20);
                $table->unsignedInteger('compared_rows');
                $table->unsignedInteger('candidate_dates_without_reference');
                $table->unsignedInteger('reference_dates_without_candidate');
                $table->string('status', 20)->default('pending');
                $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_reason')->nullable();
                $table->timestamps();

                $table->unique(
                    ['candidate_curve_version_id', 'benchmark_id', 'comparison_algorithm_version'],
                    'pu_external_validation_identity_unique',
                );
                $table->index(
                    ['candidate_curve_version_id', 'status'],
                    'pu_external_validation_candidate_index',
                );
            });
        }

        if (! Schema::hasTable('emission_pu_external_validation_rows')) {
            Schema::create('emission_pu_external_validation_rows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('external_validation_id')
                    ->constrained('emission_pu_external_validations', 'id', 'pu_external_validation_rows_fk')
                    ->restrictOnDelete();
                $table->date('reference_date');
                $table->string('candidate_unit_value', 64);
                $table->string('external_unit_value', 64);
                $table->string('absolute_difference', 64);
                $table->string('relative_difference_percentage', 64)->nullable();
                $table->string('classification', 64)->default('reported_without_tolerance');
                $table->timestamps();

                $table->unique(
                    ['external_validation_id', 'reference_date'],
                    'pu_external_validation_date_unique',
                );
            });
        }

        if (! Schema::hasTable('emission_pu_external_validation_gaps')) {
            Schema::create('emission_pu_external_validation_gaps', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('external_validation_id')
                    ->constrained('emission_pu_external_validations', 'id', 'pu_external_validation_gaps_fk')
                    ->restrictOnDelete();
                $table->date('reference_date');
                $table->string('gap_type', 40);
                $table->timestamps();

                $table->unique(
                    ['external_validation_id', 'reference_date', 'gap_type'],
                    'pu_external_validation_gap_unique',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('emission_pu_external_validation_gaps');
        Schema::dropIfExists('emission_pu_external_validation_rows');
        Schema::dropIfExists('emission_pu_external_validations');
        Schema::dropIfExists('emission_pu_external_benchmark_rows');
        Schema::dropIfExists('emission_pu_external_benchmarks');
    }
};
