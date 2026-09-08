<?php

namespace App\Enums;

/**
 * De onde veio a pendência que a Gestão precisa decidir.
 *
 * A origem não é rótulo: ela determina quais conclusões são admissíveis. Uma
 * declaração da construtora e um apontamento objetivo do Nimbus são fatos de
 * naturezas diferentes, e tratá-los com o mesmo conjunto de decisões permitiria
 * publicar como "exceção aprovada" um fato comercial que a própria Gestão
 * considera errado.
 *
 * A separação entre venda **fora da política** e venda **sem conformidade
 * determinável** é da mesma natureza, e é a razão de existirem dois casos de
 * sistema em vez de um. "Não foi possível determinar" não é "está conforme" nem
 * "está fora": é ausência de informação. Uma exceção só pode ser concedida
 * contra um limite conhecido -- sem saber qual era o mínimo autorizado, não há o
 * que excepcionar, e a única saída honesta é corrigir a fonte.
 *
 * @see SalesBoardNonconformityDecision::allowedFor()
 */
enum SalesBoardNonconformityOrigin: string
{
    /**
     * Os valores persistidos cabem em `varchar(30)` -- a largura da coluna
     * `origin`. Não é detalhe de estilo: o SQLite ignora o limite e o MySQL
     * trunca, então um caso novo com nome longo passaria na suíte e quebraria
     * em produção. Um valor mais curto vale mais que uma migration.
     */
    /**
     * A construtora declarou que o snapshot não confere.
     */
    case BuilderDeclared = 'declarada_construtora';

    /**
     * O Nimbus apontou, na geração, uma venda fora da política comercial
     * vigente na data em que ela ocorreu.
     */
    case SystemSaleNonConform = 'venda_nao_conforme';

    /**
     * O Nimbus não teve informação suficiente para dizer se a venda respeitou a
     * política -- faltava o valor de referência da unidade na data, a política
     * vigente, ou os dois.
     */
    case SystemSaleUndetermined = 'venda_indeterminada';

    public function label(): string
    {
        return match ($this) {
            self::BuilderDeclared => 'Declarada pela construtora',
            self::SystemSaleNonConform => 'Venda fora da política',
            self::SystemSaleUndetermined => 'Conformidade não determinada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BuilderDeclared => 'warning',
            self::SystemSaleNonConform => 'danger',
            self::SystemSaleUndetermined => 'gray',
        };
    }

    /**
     * A origem se ancora num movimento congelado do baseline.
     *
     * As duas origens de sistema apontam movimento; a declarada pela construtora
     * aponta a divergência. Perguntar isso à origem -- em vez de repetir a lista
     * em cada serviço -- é o que mantém a materialização, o guard do model e a
     * invariante da aprovação falando da mesma regra.
     */
    public function anchorsMovement(): bool
    {
        return $this !== self::BuilderDeclared;
    }

    /**
     * O status congelado de conformidade que produz esta origem, quando ela vem
     * de uma venda.
     */
    public static function forConformity(SalesPriceConformityStatus $status): ?self
    {
        return match ($status) {
            SalesPriceConformityStatus::NonConform => self::SystemSaleNonConform,
            SalesPriceConformityStatus::Undetermined => self::SystemSaleUndetermined,
            SalesPriceConformityStatus::Conform => null,
        };
    }

    /**
     * Os status de conformidade que exigem decisão da Gestão.
     *
     * `Conform` não entra: o Nimbus avaliou e a venda respeitou a política.
     * Criar pendência para ela afogaria a análise no que está certo.
     *
     * @return list<SalesPriceConformityStatus>
     */
    public static function decidableConformityStatuses(): array
    {
        return [
            SalesPriceConformityStatus::NonConform,
            SalesPriceConformityStatus::Undetermined,
        ];
    }

    public function description(): string
    {
        return match ($this) {
            self::BuilderDeclared => 'A construtora afirma que o fato congelado não corresponde aos registros dela.',
            self::SystemSaleNonConform => 'A venda foi realizada abaixo do preço mínimo autorizado pela política vigente na data.',
            self::SystemSaleUndetermined => 'O Nimbus não conseguiu determinar a conformidade desta venda: falta o dado contra o qual ela seria avaliada.',
        };
    }
}
