<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devolve o banco ao estado recém-migrado depois de um teste do grupo `mysql`.
 *
 * Esses testes não usam `RefreshDatabase`: o cenário e os processos filhos
 * escrevem em conexões próprias e commitam, porque é isso que se quer observar.
 * O arquivo seguinte da suíte, porém, usa `RefreshDatabase`, que numa execução
 * completa já migrou antes e só abre uma transação sobre o que encontrar. Uma
 * política de desconto esquecida aqui vira um `sole()` quebrado lá.
 *
 * Apagar tabela por tabela, com uma lista escrita à mão, esquece a próxima
 * tabela que o fixture passar a preencher. Um `migrate:fresh` no fim de cada
 * teste resolveria, mas custa perto de um minuto no MySQL. Então a foto é
 * tirada logo depois do `migrate:fresh` do `beforeEach`: as tabelas que as
 * migrations deixaram vazias são esvaziadas de novo, e nas que elas povoam
 * (permissões, papéis, o tipo de prestador padrão, o registro de migrations)
 * sai só o que foi inserido depois da foto -- a factory de empreendimento,
 * por exemplo, cria o tipo "engenharia" ao lado do que a migration semeou.
 *
 * {@see self::leftovers()} é a prova de que a limpeza bastou, inclusive numa
 * tabela povoada pelas migrations e sem `id`, que esta classe não sabe limpar.
 */
final class CommittedRowsSweeper
{
    /**
     * @param  array<string, int>  $rowsSeededByMigrations  linhas por tabela logo depois das migrations, só das tabelas não vazias
     * @param  array<string, int>  $lastSeededIds  maior `id` de cada tabela povoada que tem essa coluna
     * @param  list<string>  $tables
     */
    private function __construct(
        private readonly array $rowsSeededByMigrations,
        private readonly array $lastSeededIds,
        private readonly array $tables,
    ) {}

    /**
     * Fotografa o banco. Chamar logo depois do `migrate:fresh`.
     */
    public static function afterFreshMigration(): self
    {
        $tables = Schema::getTableListing(Schema::getCurrentSchemaListing(), false);
        $seeded = [];
        $lastIds = [];

        foreach ($tables as $table) {
            $rows = DB::table($table)->count();

            if ($rows === 0) {
                continue;
            }

            $seeded[$table] = $rows;

            if (Schema::hasColumn($table, 'id')) {
                $lastIds[$table] = (int) DB::table($table)->max('id');
            }
        }

        return new self($seeded, $lastIds, $tables);
    }

    /**
     * Esvazia toda tabela que as migrations deixaram vazia e tira das povoadas
     * o que entrou depois da foto.
     *
     * Pelo query builder e com as chaves estrangeiras suspensas: os models de
     * snapshot recusam `delete()`, como devem em produção, e a ordem entre
     * tabelas deixa de importar.
     */
    public function sweep(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach ($this->tables as $table) {
                if (array_key_exists($table, $this->lastSeededIds)) {
                    DB::table($table)->where('id', '>', $this->lastSeededIds[$table])->delete();

                    continue;
                }

                if (array_key_exists($table, $this->rowsSeededByMigrations)) {
                    continue;
                }

                if (DB::table($table)->exists()) {
                    DB::table($table)->delete();
                }
            }
        });
    }

    /**
     * O que ainda difere do estado recém-migrado: tabelas que deviam estar
     * vazias e não estão, e tabelas povoadas pelas migrations cuja contagem
     * mudou.
     *
     * @return array<string, int> linhas atuais por tabela divergente
     */
    public function leftovers(): array
    {
        $leftovers = [];

        foreach ($this->tables as $table) {
            $rows = DB::table($table)->count();

            if ($rows !== ($this->rowsSeededByMigrations[$table] ?? 0)) {
                $leftovers[$table] = $rows;
            }
        }

        return $leftovers;
    }
}
