<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `responsibility_delegations` é trilha auditável: registra quem delegou, para
 * quem, em que escopo, por qual período e por qual motivo -- e continua
 * registrando depois de revogada. Mas as três chaves que dão sentido à linha
 * apagavam a linha junto com a entidade referenciada.
 *
 * Nem `users` nem `operations` têm SoftDeletes: hard delete é o único delete dos
 * dois, e é alcançável pela interface. Apagar um usuário levava embora toda
 * delegação em que ele aparecesse como delegante ou delegado, deixando o
 * `activity_log` -- que não tem chave estrangeira -- apontando para uma linha que
 * não existe mais. O histórico não sumia por decisão: sumia por cascade.
 *
 * RESTRICT inverte o padrão: a entidade com histórico não pode ser apagada. É o
 * comportamento que o resto do schema já tem por outro caminho -- as demais
 * referências históricas a `users` são anuláveis e usam SET NULL --, e que aqui
 * não podia ser obtido assim: `delegator_user_id` e `delegate_user_id` são NOT
 * NULL, e anular `scope_operation_id` seria pior que apagar. Para
 * `scope_type = 'stage'`, `scope_operation_id IS NULL` significa "vale para
 * todas as operações": SET NULL transformaria uma delegação de etapa restrita a
 * uma operação em delegação de etapa irrestrita, o que é escalada de privilégio.
 *
 * `revoked_by` e `created_by` continuam SET NULL: são autoria, não vínculo, e
 * uma delegação sem o registro de quem a criou ainda é uma delegação legível.
 *
 * As chaves são derrubadas e recriadas pelo nome convencional
 * (`tabela_coluna_foreign`, todos abaixo dos 64 caracteres do MySQL), declaradas
 * por coluna e não por nome porque é essa a forma que o SQLite também aceita --
 * lá a alteração vira reconstrução da tabela, e o schema lógico fica igual nos
 * dois bancos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoOrphans();

        Schema::table('responsibility_delegations', function (Blueprint $table): void {
            $table->dropForeign(['delegator_user_id']);
            $table->dropForeign(['delegate_user_id']);
            $table->dropForeign(['scope_operation_id']);

            $table->foreign('delegator_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('delegate_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('scope_operation_id')->references('id')->on('operations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('responsibility_delegations', function (Blueprint $table): void {
            $table->dropForeign(['delegator_user_id']);
            $table->dropForeign(['delegate_user_id']);
            $table->dropForeign(['scope_operation_id']);

            $table->foreign('delegator_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('delegate_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('scope_operation_id')->references('id')->on('operations')->cascadeOnDelete();
        });
    }

    /**
     * Recusa antes de qualquer DDL.
     *
     * DDL em MySQL não faz rollback: uma linha órfã descoberta só na hora de
     * recriar a chave deixaria a tabela sem as três chaves antigas e sem as
     * novas. Contar primeiro custa três consultas e falha com o número em vez do
     * errno -- e falhar é o comportamento certo: uma delegação apontando para
     * usuário ou operação inexistente é dado que alguém precisa olhar, não dado
     * que a migration deva consertar por conta própria.
     */
    private function assertNoOrphans(): void
    {
        $orphans = [
            'delegator_user_id' => DB::table('responsibility_delegations')
                ->whereNotIn('delegator_user_id', DB::table('users')->select('id'))
                ->count(),
            'delegate_user_id' => DB::table('responsibility_delegations')
                ->whereNotIn('delegate_user_id', DB::table('users')->select('id'))
                ->count(),
            'scope_operation_id' => DB::table('responsibility_delegations')
                ->whereNotNull('scope_operation_id')
                ->whereNotIn('scope_operation_id', DB::table('operations')->select('id'))
                ->count(),
        ];

        $found = array_filter($orphans);

        if ($found === []) {
            return;
        }

        throw new RuntimeException(
            'responsibility_delegations possui linhas órfãs e as chaves RESTRICT não podem ser criadas: '
                .json_encode($found)
                .'. Investigue a origem antes de migrar; esta migration não apaga nem corrige linha alguma.'
        );
    }
};
