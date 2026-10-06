<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leva para a categoria protegida `measurements` a trilha de planos de medição
 * e de linhas do cronograma que ficou em `default`.
 *
 * Até aqui `MeasurementPlanSet` e `MeasurementPlanLine` gravavam sem
 * `useLogName()`, no balde que o `audit:clean-filtered` descarta em um ano. O
 * plano agora carrega o avanço físico inicial da obra, e a linha guarda o
 * "Realiz. inicial" de onde veio o avanço inicial dos planos antigos; os dois
 * gravam em `measurements`. Sem esta reclassificação, quem criou cada plano e
 * quem digitou cada valor de origem, e quando, continuariam expirando.
 *
 * Mesmo desenho de 2026_10_01_144326: só dados, ids lidos pelo índice
 * `subject`, atualização pela chave primária em blocos curtos, `subject_type`
 * literal e execução idempotente.
 */
return new class extends Migration
{
    /** @var list<string> subject_type gravado */
    private const SUBJECT_TYPES = [
        'App\Models\MeasurementPlanSet',
        'App\Models\MeasurementPlanLine',
    ];

    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        foreach (self::SUBJECT_TYPES as $subjectType) {
            DB::table('activity_log')
                ->where('subject_type', $subjectType)
                ->where('log_name', 'default')
                ->pluck('id')
                ->chunk(self::CHUNK_SIZE)
                ->each(fn (Collection $ids): int => DB::table('activity_log')
                    ->whereIn('id', $ids->values()->all())
                    ->update(['log_name' => 'measurements']));
        }
    }

    /**
     * Mão única, de propósito: devolver as linhas a `default` apagaria em um
     * ano evidência que passou a ser protegida, e as gravadas depois desta
     * migração já nascem em `measurements`, sem como distinguir umas das outras.
     */
    public function down(): void
    {
        //
    }
};
