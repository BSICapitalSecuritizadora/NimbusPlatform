<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Enums\SalesBoardIssueCode;
use Illuminate\Support\HtmlString;

/**
 * Os códigos da apuração -- bloqueios da prontidão e avisos congelados com a
 * versão -- em linguagem de quem opera.
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
     * Os avisos congelados de uma versão (ou de uma homologação), agrupados por
     * código para a tela.
     *
     * `null` quando o registro não existe -- versão congelada antes de os avisos
     * passarem a ser gravados --, para a tela dizer isso em vez de "nenhum
     * aviso". Os grupos saem na ordem em que os avisos foram congelados (código,
     * unidade, contrato), e cada um traz no máximo `$itemsPerCode` itens, com o
     * que sobrou em `hidden`.
     *
     * Para a construtora (`$forBuilder`) só entram os códigos que
     * {@see SalesBoardIssueCode::isVisibleToBuilder()} libera, e sem a dica: ela
     * fala de telas internas. O código técnico continua no grupo para quem
     * monta a tela decidir; a Validação não o mostra. Código desconhecido não
     * chega à construtora.
     *
     * @param  list<array<string, mixed>>|null  $frozen  a lista de {@see SalesBoardFrozenWarnings::fromPosition()}
     * @param  list<SalesBoardIssueCode|string>  $except  códigos que a tela já mostra de outro jeito
     * @return list<array{code: string, label: string, hint: string|null, count: int, items: list<array{unit: string|null, contract: string|null, message: string}>, hidden: int}>|null
     */
    public static function groupFrozen(?array $frozen, bool $forBuilder = false, array $except = [], int $itemsPerCode = 20): ?array
    {
        if ($frozen === null) {
            return null;
        }

        $excluded = array_flip(array_map(
            static fn (SalesBoardIssueCode|string $code): string => $code instanceof SalesBoardIssueCode ? $code->value : $code,
            $except,
        ));

        $groups = [];

        foreach ($frozen as $warning) {
            $code = (string) ($warning['code'] ?? '');

            if (($code === '') || isset($excluded[$code])) {
                continue;
            }

            $issue = SalesBoardIssueCode::tryFrom($code);

            if ($forBuilder && ! ($issue?->isVisibleToBuilder() ?? false)) {
                continue;
            }

            $groups[$code] ??= [
                'code' => $code,
                'label' => $issue?->label() ?? $code,
                'hint' => ($forBuilder || ($issue === null)) ? null : self::hint($issue),
                'count' => 0,
                'items' => [],
                'hidden' => 0,
            ];

            $groups[$code]['count']++;

            if (count($groups[$code]['items']) >= $itemsPerCode) {
                $groups[$code]['hidden']++;

                continue;
            }

            $groups[$code]['items'][] = [
                'unit' => filled($warning['unit_label'] ?? null) ? (string) $warning['unit_label'] : null,
                'contract' => filled($warning['contract_code'] ?? null) ? (string) $warning['contract_code'] : null,
                'message' => (string) ($warning['message'] ?? ''),
            ];
        }

        return array_values($groups);
    }

    /**
     * Onde a fonte é corrigida. Nunca "afrouxe a regra": o bloqueio é ausência
     * de dado, e o remédio é cadastrar o dado.
     *
     * Quando a tela não oferece o cadastro -- permuta com a operação em curso é
     * decisão da Gestão --, a dica diz a quem levar o caso em vez de apontar um
     * caminho que não existe. Sem `default`: código novo precisa de dica.
     */
    private static function hint(SalesBoardIssueCode $code): string
    {
        return match ($code) {
            SalesBoardIssueCode::NoConstructionUnits => 'Cadastre as unidades do empreendimento em Obras › Unidades.',
            SalesBoardIssueCode::AmbiguousOccupancy => 'Revise os contratos da unidade: apenas um pode ocupá-la na data da posição.',
            SalesBoardIssueCode::AmbiguousExchange => 'A unidade tem mais de uma permuta vigente na data: uma delas precisa deixar de valer. Leve o caso à Gestão (Encerrar ou Substituir permuta).',
            SalesBoardIssueCode::ExchangeOccupancyConflict => 'A permuta vigente não bate com o contrato que ocupa a unidade: aponta para outro contrato, ou não tem contrato e a unidade está com uma venda comum. Confira o contrato; se o erro estiver na permuta, leve o caso à Gestão (Encerrar ou Substituir permuta).',
            SalesBoardIssueCode::ExchangeSourceMissing => 'O contrato está como permutado, mas a unidade não tem permuta registrada. A permuta inicial é declarada pela tela (Unidades › Permutas) enquanto a Emissão está em elaboração e a obra ainda não tem competência apurada; com a operação em curso, leve o caso à Gestão: ela registra a permuta extraordinária ou, se a permuta foi desfeita, a encerra, o que distrata o contrato de permuta. Se a permuta já está encerrada e o contrato continua como permutado, a Gestão usa “Distratar contrato de permuta” na permuta encerrada.',
            SalesBoardIssueCode::SettlementUndetermined => 'Confira as parcelas do contrato: vencimentos e pagamentos precisam explicar a quitação na data. Se o contrato é de permuta, o que falta é a permuta registrada na unidade -- ou, se ela já foi encerrada, o distrato do contrato de permuta (“Distratar contrato de permuta”, pela Gestão).',
            SalesBoardIssueCode::UnitValueMissing => 'Registre o valor da unidade vigente na data da posição: “Atualizar Valores” em Obras › Unidades (em lote) ou “Atualizar valor” na aba Histórico de Valores da unidade.',
            SalesBoardIssueCode::SaleUnitValueMissing => 'Registre o valor de referência da unidade vigente na data da venda: “Atualizar Valores” em Obras › Unidades (em lote) ou “Atualizar valor” na aba Histórico de Valores da unidade.',
            SalesBoardIssueCode::SaleDiscountPolicyMissing => 'Cadastre a política de desconto vigente na data da venda: na obra, aba Política Comercial de Desconto › “Nova política”. Como a venda já aconteceu, a política alcança venda já registrada e é registrada pela Gestão (permissão de aprovação do Quadro de Vendas).',
            SalesBoardIssueCode::UnitConstructionMismatch => 'Corrija o empreendimento do contrato ou da unidade: os dois precisam ser o mesmo.',
            SalesBoardIssueCode::SaleNonConform => 'Não impede a apuração: a venda será analisada pela Gestão.',
            SalesBoardIssueCode::SettlementStatusDivergence => 'Não impede a apuração: o Quadro segue o cronograma de parcelas. Confira as parcelas do contrato -- desconto não registrado num pagamento abaixo do previsto, parcelas renegociadas sem data de cancelamento, distrato ainda não lançado -- ou corrija o status do contrato.',
            SalesBoardIssueCode::FutureSaleDate => 'Não impede a apuração, mas a unidade conta como estoque até a data da venda: confira a data da venda do contrato.',
            SalesBoardIssueCode::CancelledContractWithoutDate => 'Informe a data do distrato no contrato (Contratos › editar), ou corrija o status se o contrato não foi distratado.',
            SalesBoardIssueCode::ExchangeValueMissing => 'Permuta sem valor não compõe o Quadro. Corrija o valor por “Substituir permuta” na aba Permutas da unidade: com a operação em curso, é ação da Gestão.',
            SalesBoardIssueCode::SaleValueOutOfScale => 'Um dos dois valores foi lido ou digitado errado: confira o valor da venda do contrato e o valor de referência da unidade (aba Histórico de Valores) e corrija o que estiver fora de escala.',
            SalesBoardIssueCode::InstallmentValueOutOfScale => 'Confira o valor previsto e o valor pago das parcelas do contrato: uma parcela não chega a dez vezes o valor da venda. Corrija a parcela pela tela ou reimporte a planilha corrigida.',
            SalesBoardIssueCode::SourceDateBefore1990 => 'Uma data que decide a posição é anterior a 1990 -- quase sempre o ano digitado errado (0026, 0202). Corrija a data no contrato, na parcela ou no valor da unidade; a permuta, que não se edita, é corrigida por “Substituir permuta” (Unidades › Permutas; com a operação em curso, pela Gestão).',
            SalesBoardIssueCode::SaleValueAtypical => 'Não impede a apuração: confira se o valor da venda e o valor de referência da unidade estão certos.',
            SalesBoardIssueCode::InstallmentValueAtypical => 'Não impede a apuração: confira o valor previsto e o valor pago da parcela indicada (um pagamento lido como milhar, por exemplo).',
            SalesBoardIssueCode::UnderpaidInstallments => 'Não impede a apuração, mas o contrato segue financiado enquanto o pago não cobrir o previsto. Se houve desconto na baixa (pontualidade, antecipação), registre o “Desconto concedido” nas parcelas; se o recebimento está incompleto, nada a fazer.',
            SalesBoardIssueCode::PaymentDateInFuture => 'Não impede a apuração: um recebimento só é registrado depois de acontecer. Confira a data do pagamento da parcela.',
            SalesBoardIssueCode::UnitRetired => 'Não impede a apuração: a unidade deixou de compor o Quadro pela baixa registrada. Confira a baixa na aba “Baixas” da unidade.',
            SalesBoardIssueCode::UnitReactivated => 'Não impede a apuração: a unidade voltou a compor o Quadro pela reativação registrada na aba “Baixas” da unidade.',
            SalesBoardIssueCode::RetiredUnitInUse => 'A unidade está baixada, mas um contrato ou uma permuta a ocupa na data da posição. Se a venda ou a permuta é real, reative a unidade (Obras › Unidades › Baixas); se não, corrija o contrato ou leve a permuta à Gestão.',
            SalesBoardIssueCode::LateSaleNonConform => 'Não impede a apuração: a venda é de competência anterior e entra nesta como movimento extemporâneo (ou revisão de venda publicada). A Gestão decide a exceção ou a correção na análise.',
            SalesBoardIssueCode::LateSaleUndetermined => 'Não impede a apuração: a venda é de competência anterior e a conformidade dela não pôde ser determinada. A Gestão decide na análise; cadastrar o valor da unidade na data da venda e recalcular resolve quando é ele que falta.',
            SalesBoardIssueCode::UnexplainedReclassification => 'Não impede a apuração: confira na “Ponte com a competência anterior” o que mudou na unidade (estorno de quitação, data de venda movida, permuta, inclusão ou saída do inventário). Para corrigir a posição publicada da última competência, a Gestão pode usar “Retificar competência”.',
        };
    }
}
