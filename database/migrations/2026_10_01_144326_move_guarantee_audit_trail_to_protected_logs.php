<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leva para as categorias protegidas a trilha de garantias que ficou em
 * `default`.
 *
 * Até aqui `Guarantee` e `GuaranteeSnapshot` gravavam sem `useLogName()`, no
 * balde que o `audit:clean-filtered` descarta em um ano. O código agora grava
 * em `guarantees` e `guarantee_competences`, protegidas em `config/audit.php`;
 * sem esta reclassificação, o histórico já gravado continuaria expirando --
 * justamente o dos primeiros fechamentos.
 *
 * Só dados, sem DDL. Os ids são lidos pelo índice `subject` (subject_type,
 * subject_id), sem varrer a tabela por id, e atualizados pela chave primária em
 * blocos curtos: o lock fica nas linhas tocadas. Nada muda além da categoria.
 *
 * O `subject_type` vai literal, e não `::class`: é o valor gravado na linha, e
 * ele não acompanharia uma renomeação futura do model.
 *
 * Idempotente: a segunda execução não encontra mais nada em `default`.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> subject_type gravado => categoria protegida
     */
    private const CATEGORIES = [
        'App\Models\GuaranteeSnapshot' => 'guarantee_competences',
        'App\Models\Guarantee' => 'guarantees',
    ];

    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        foreach (self::CATEGORIES as $subjectType => $logName) {
            DB::table('activity_log')
                ->where('subject_type', $subjectType)
                ->where('log_name', 'default')
                ->pluck('id')
                ->chunk(self::CHUNK_SIZE)
                ->each(fn (Collection $ids): int => DB::table('activity_log')
                    ->whereIn('id', $ids->values()->all())
                    ->update(['log_name' => $logName]));
        }
    }

    /**
     * Mão única, de propósito.
     *
     * Devolver as linhas a `default` as colocaria de novo no balde que expira
     * em um ano, apagando evidência que passou a ser protegida -- e as linhas
     * gravadas depois desta migração já nascem na categoria certa, sem como
     * distinguir umas das outras.
     */
    public function down(): void
    {
        //
    }
};
