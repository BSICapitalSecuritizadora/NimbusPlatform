<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Governança da curva candidata de PU (2B.5.16).
 *
 * Acrescenta a dimensão ortogonal `curve_role` (operational|candidate) às versões de
 * curva, os campos de identidade/review do artefato candidato, e o vínculo forte
 * `emission_pu_daily_curves.curve_version_id`.
 *
 * A ordem das operações é deliberada: as colunas nascem nullable, o backfill roda,
 * a não-nulidade é fixada com `change()` e só depois a FK das linhas é criada. No
 * SQLite `change()` recria a tabela, então fixar a nulidade antes de existir uma FK
 * externa apontando para `emission_pu_curve_versions` evita reconstruir a tabela
 * enquanto ela é referenciada.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('emission_pu_curve_versions', function (Blueprint $table): void {
            $table->string('curve_role', 20)->nullable()->after('calculation_version');
            $table->date('candidate_as_of')->nullable()->after('curve_role');
            $table->char('input_fingerprint', 64)->nullable()->after('candidate_as_of');
            $table->char('curve_checksum', 64)->nullable()->after('input_fingerprint');
            $table->string('internal_validation_status', 20)->nullable()->after('curve_checksum');
            $table->string('external_validation_status', 20)->nullable()->after('internal_validation_status');
            $table->string('review_status', 30)->nullable()->after('external_validation_status');
            $table->foreignId('reviewed_by')->nullable()->after('invalidated_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('invalidated_at');
            $table->text('review_reason')->nullable()->after('reviewed_at');
        });

        $this->backfillHistoricalOperationalRoles();

        Schema::table('emission_pu_curve_versions', function (Blueprint $table): void {
            $table->string('curve_role', 20)->nullable(false)->change();
            $table->string('review_status', 30)->nullable(false)->change();
        });

        Schema::table('emission_pu_curve_versions', function (Blueprint $table): void {
            $table->index(
                ['emission_id', 'curve_role', 'id'],
                'pu_curve_versions_emission_role_latest_index',
            );
            $table->index(
                ['emission_id', 'curve_role', 'candidate_as_of'],
                'pu_curve_versions_candidate_lookup_index',
            );
            $table->unique(
                ['emission_id', 'curve_role', 'candidate_as_of', 'input_fingerprint', 'curve_checksum'],
                'pu_curve_versions_candidate_identity_unique',
            );
        });

        Schema::table('emission_pu_daily_curves', function (Blueprint $table): void {
            $table->foreignId('curve_version_id')
                ->nullable()
                ->after('emission_id')
                ->constrained('emission_pu_curve_versions')
                ->restrictOnDelete();
        });

        $this->backfillUnambiguousRowLinks();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emission_pu_daily_curves', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('curve_version_id');
        });

        Schema::table('emission_pu_curve_versions', function (Blueprint $table): void {
            $table->dropUnique('pu_curve_versions_candidate_identity_unique');
            $table->dropIndex('pu_curve_versions_candidate_lookup_index');
            $table->dropIndex('pu_curve_versions_emission_role_latest_index');
        });

        Schema::table('emission_pu_curve_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'curve_role',
                'candidate_as_of',
                'input_fingerprint',
                'curve_checksum',
                'internal_validation_status',
                'external_validation_status',
                'review_status',
                'reviewed_at',
                'review_reason',
            ]);
        });
    }

    /**
     * Toda versão já existente é operacional por definição: candidate só passa a
     * existir a partir do writer desta fase. Nada é inferido.
     */
    private function backfillHistoricalOperationalRoles(): void
    {
        DB::table('emission_pu_curve_versions')
            ->whereNull('curve_role')
            ->update(['curve_role' => 'operational']);

        DB::table('emission_pu_curve_versions')
            ->whereNull('review_status')
            ->update(['review_status' => 'not_applicable']);
    }

    /**
     * Liga cada linha histórica à sua versão apenas quando o par
     * (emission_id, calculation_version) resolve para exatamente uma versão.
     *
     * Zero versões correspondentes ou mais de uma: a linha permanece com
     * `curve_version_id = null` e continua sendo lida como operacional legada
     * pelo scope do model. Nenhuma linha é ligada a uma versão possivelmente
     * errada.
     */
    private function backfillUnambiguousRowLinks(): void
    {
        $versionsByKey = [];

        foreach (
            DB::table('emission_pu_curve_versions')
                ->select(['id', 'emission_id', 'calculation_version'])
                ->orderBy('id')
                ->get() as $version
        ) {
            $key = $this->versionKey($version->emission_id, $version->calculation_version);
            $versionsByKey[$key][] = (int) $version->id;
        }

        $rowGroups = DB::table('emission_pu_daily_curves')
            ->select(['emission_id', 'calculation_version'])
            ->whereNull('curve_version_id')
            ->groupBy('emission_id', 'calculation_version')
            ->orderBy('emission_id')
            ->orderBy('calculation_version')
            ->get();

        foreach ($rowGroups as $rowGroup) {
            $matches = $versionsByKey[$this->versionKey($rowGroup->emission_id, $rowGroup->calculation_version)] ?? [];

            if (count($matches) !== 1) {
                continue;
            }

            DB::table('emission_pu_daily_curves')
                ->where('emission_id', $rowGroup->emission_id)
                ->where('calculation_version', $rowGroup->calculation_version)
                ->whereNull('curve_version_id')
                ->update(['curve_version_id' => $matches[0]]);
        }
    }

    private function versionKey(mixed $emissionId, mixed $calculationVersion): string
    {
        return (string) $emissionId.'|'.(string) $calculationVersion;
    }
};
