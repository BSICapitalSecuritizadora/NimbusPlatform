<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emission_pu_curve_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->string('calculation_version');
            $table->uuid('batch_id')->unique();
            $table->string('status')->default('pending');
            $table->string('engine_version')->nullable();
            $table->json('parameters_snapshot')->nullable();
            $table->unsignedInteger('rows_count')->nullable();
            $table->text('error_message')->nullable();
            $table->json('validation_summary')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('homologated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invalidated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('homologated_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->index(['emission_id', 'status']);
            $table->index(['emission_id', 'calculation_version']);
        });

        $this->backfillExistingVersions();
    }

    public function down(): void
    {
        Schema::dropIfExists('emission_pu_curve_versions');
    }

    /**
     * Cria uma versao por (emission_id, calculation_version) ja existente,
     * marcando a mais recente de cada emissao como "generated" e as demais como "obsolete".
     */
    private function backfillExistingVersions(): void
    {
        $grouped = DB::table('emission_pu_daily_curves')
            ->select('emission_id', 'calculation_version', DB::raw('COUNT(*) as rows_count'), DB::raw('MAX(id) as last_id'))
            ->whereNotNull('calculation_version')
            ->groupBy('emission_id', 'calculation_version')
            ->get();

        if ($grouped->isEmpty()) {
            return;
        }

        $timestamp = now();
        $latestByEmission = [];

        foreach ($grouped as $group) {
            $current = $latestByEmission[$group->emission_id] ?? null;

            if ($current === null || $group->last_id > $current->last_id) {
                $latestByEmission[$group->emission_id] = $group;
            }
        }

        $rows = $grouped->map(function ($group) use ($timestamp, $latestByEmission): array {
            $isCurrent = ($latestByEmission[$group->emission_id]->last_id ?? null) === $group->last_id;

            return [
                'emission_id' => $group->emission_id,
                'calculation_version' => $group->calculation_version,
                'batch_id' => (string) Str::uuid(),
                'status' => $isCurrent ? 'generated' : 'obsolete',
                'engine_version' => 'phase1-cdi-v1',
                'parameters_snapshot' => null,
                'rows_count' => $group->rows_count,
                'error_message' => null,
                'validation_summary' => null,
                'generated_by' => null,
                'validated_by' => null,
                'homologated_by' => null,
                'invalidated_by' => null,
                'generated_at' => $timestamp,
                'validated_at' => null,
                'homologated_at' => null,
                'invalidated_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        })->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('emission_pu_curve_versions')->insert($chunk);
        }
    }
};
