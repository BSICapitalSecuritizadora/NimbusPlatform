<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Enums\SalesBoardIssueCode;
use Illuminate\Support\HtmlString;

/**
 * Os códigos de bloqueio da prontidão, em linguagem de quem opera.
 *
 * Apresentação apenas. O domínio continua produzindo e persistindo o código
 * (`UNIT_VALUE_MISSING (3)`) -- é ele que suporte, auditoria e log leem --, e esta
 * classe não o substitui: acrescenta, ao lado dele, o que ele significa e onde se
 * corrige. O rótulo vem de {@see SalesBoardIssueCode::label()}, que já existia;
 * o que nasce aqui é só a indicação de onde a fonte é corrigida.
 *
 * Um código que o enum não conhece aparece como veio, sem tradução inventada.
 */
final class SalesBoardIssuePresenter
{
    /**
     * @param  array<string, int>|list<string>  $codes  contagem por código, como `blockingIssueCounts()`, ou a lista persistida de códigos
     * @return list<array{code: string, label: string, hint: string|null, count: int|null}>
     */
    public static function describe(array $codes): array
    {
        $described = [];

        foreach ($codes as $key => $value) {
            [$code, $count] = is_string($key) ? [$key, (int) $value] : [(string) $value, null];

            $issue = SalesBoardIssueCode::tryFrom($code);

            $described[] = [
                'code' => $code,
                'label' => $issue?->label() ?? $code,
                'hint' => $issue === null ? null : self::hint($issue),
                'count' => $count,
            ];
        }

        return $described;
    }

    /**
     * Uma linha por bloqueio, para corpo de notificação: descrição primeiro,
     * código entre parênteses, onde corrigir em seguida.
     *
     * @param  array<string, int>|list<string>  $codes
     */
    public static function toHtml(array $codes): HtmlString
    {
        $lines = array_map(
            fn (array $issue): string => sprintf(
                '• %s (%s%s)%s',
                e($issue['label']),
                e($issue['code']),
                $issue['count'] === null ? '' : ' · '.$issue['count'],
                $issue['hint'] === null ? '' : '<br>&nbsp;&nbsp;'.e($issue['hint']),
            ),
            self::describe($codes),
        );

        return new HtmlString(implode('<br>', $lines));
    }

    /**
     * Onde a fonte é corrigida. Nunca "afrouxe a regra": o bloqueio é ausência
     * de dado, e o remédio é cadastrar o dado.
     *
     * Quando a tela não oferece o cadastro -- permuta com a operação em curso não
     * se registra nem se edita por aqui --, a dica diz isso em vez de apontar um
     * caminho que não existe.
     */
    private static function hint(SalesBoardIssueCode $code): string
    {
        return match ($code) {
            SalesBoardIssueCode::NoConstructionUnits => 'Cadastre as unidades do empreendimento em Obras › Unidades.',
            SalesBoardIssueCode::AmbiguousOccupancy => 'Revise os contratos da unidade: apenas um pode ocupá-la na data da posição.',
            SalesBoardIssueCode::AmbiguousExchange => 'A unidade tem mais de uma permuta vigente na data. Permutas não são editadas nem excluídas pela tela: leve o caso à Gestão.',
            SalesBoardIssueCode::ExchangeOccupancyConflict => 'A permuta vigente aponta para outro contrato que não o que ocupa a unidade. Confira o contrato; se o erro estiver na permuta, leve o caso à Gestão, porque permutas não são editadas pela tela.',
            SalesBoardIssueCode::ExchangeSourceMissing => 'O contrato está como permutado, mas a unidade não tem permuta registrada. A permuta só é declarada pela tela (Unidades › Permutas) enquanto a Emissão está em elaboração; com a operação em curso, leve o caso à Gestão.',
            SalesBoardIssueCode::SettlementUndetermined => 'Confira as parcelas do contrato: vencimentos e pagamentos precisam explicar a quitação na data. Se o contrato é de permuta, o que falta é a permuta registrada na unidade.',
            SalesBoardIssueCode::UnitValueMissing => 'Registre o valor da unidade vigente na data da posição: “Atualizar Valores” em Obras › Unidades (em lote) ou “Atualizar valor” na aba Histórico de Valores da unidade.',
            SalesBoardIssueCode::SaleUnitValueMissing => 'Registre o valor de referência da unidade vigente na data da venda: “Atualizar Valores” em Obras › Unidades (em lote) ou “Atualizar valor” na aba Histórico de Valores da unidade.',
            SalesBoardIssueCode::SaleDiscountPolicyMissing => 'Cadastre a política de desconto vigente na data da venda: na obra, aba Política Comercial de Desconto › “Nova política”.',
            SalesBoardIssueCode::UnitConstructionMismatch => 'Corrija o empreendimento do contrato ou da unidade: os dois precisam ser o mesmo.',
            SalesBoardIssueCode::SaleNonConform => 'Não impede a apuração: a venda será analisada pela Gestão.',
        };
    }
}
