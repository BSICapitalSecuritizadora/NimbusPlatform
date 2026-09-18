<?php

declare(strict_types=1);

namespace Tests\Support\Pu;

/**
 * Valores OBSERVADOS na planilha/sistema legado da 1ª Série do Alto Bellevue.
 *
 * REGRA DESTE ARQUIVO: só entra aqui número que foi efetivamente lido na
 * referência. Data sem valor lido fica `null` -- nunca preenchida por dedução,
 * interpolação ou "o que faria o teste passar". Uma expectativa inventada
 * transformaria a paridade num espelho do próprio Nimbus.
 *
 * Cenário da referência (NÃO é o cenário sintético de `PuSimulationFixture`):
 *  - primeira integralização 15/05/2026, VNU R$ 1.000,00, 100% CDI, spread 6% a.a.;
 *  - base 252, lookup BusinessDayLagExact, lag -5 DU;
 *  - calendário de accrual E de observação: BR_FINANCIAL_MARKET;
 *  - série de CDI REAL do período, que varia ao longo da janela.
 *
 * O fixture padrão da suíte usa BR_NATIONAL_HOLIDAYS e uma taxa sintética
 * constante. Por isso a paridade numérica linha a linha depende de carregar a
 * série real de CDI -- ver `PuLegacyCompatibilityTest`.
 */
final class PuLegacyReferenceFixture
{
    /**
     * Artefato de representação do sistema legado.
     *
     * Vários valores da planilha terminam em ...99 exatamente uma unidade da 8ª
     * casa abaixo de um múltiplo de 1e-6 (0,76537599 para 0,765376;
     * 16,88596199 para 16,885962; 17,54936399 para 17,549364). O padrão é o de
     * um valor decimal exato armazenado em binário e depois CORTADO em 8 casas:
     * o double imediatamente inferior sobrevive ao corte.
     *
     * O Nimbus não reproduz isso, e deliberadamente: seria introduzir `float`
     * no cálculo financeiro para imitar um artefato de hardware. A diferença
     * residual é reportada, nunca compensada.
     */
    public const BINARY_REPRESENTATION_ARTEFACT = '0.00000001';

    /**
     * Linhas observadas. `pu`, `payment` e `interest` são valores unitários em
     * reais, com 8 casas. `null` = não fornecido pela referência.
     *
     * @return array<string, array{pu:?string, payment:?string, interest:?string, note:?string}>
     */
    public static function rows(): array
    {
        return [
            '2026-05-18' => ['pu' => null, 'payment' => null, 'interest' => null, 'note' => 'Valor da planilha não fornecido.'],
            '2026-05-19' => ['pu' => null, 'payment' => null, 'interest' => null, 'note' => 'Valor da planilha não fornecido.'],
            '2026-06-05' => ['pu' => null, 'payment' => null, 'interest' => null, 'note' => 'Valor da planilha não fornecido.'],
            '2026-06-08' => [
                'pu' => '1011.54235399',
                'payment' => null,
                'interest' => null,
                // Divergência DOCUMENTAL, não de precisão: o Termo prevê o prêmio dos 2 DU
                // anteriores à integralização e a referência não o contempla. É por isso que
                // esta data é tratada em separado da paridade de precisão.
                'note' => 'Primeiro cupom: a referência não aplica o prêmio contratual de 2 DU.',
            ],
            '2026-06-09' => ['pu' => '1000.76537599', 'payment' => null, 'interest' => null, 'note' => null],
            '2026-06-10' => ['pu' => null, 'payment' => null, 'interest' => null, 'note' => 'Valor da planilha não fornecido.'],
            '2026-07-08' => ['pu' => '1016.88596199', 'payment' => '16.88596199', 'interest' => null, 'note' => null],
            '2026-07-09' => ['pu' => null, 'payment' => null, 'interest' => null, 'note' => 'Valor da planilha não fornecido.'],
            '2026-08-10' => ['pu' => '1017.54936399', 'payment' => '17.54936399', 'interest' => null, 'note' => null],
            '2026-08-11' => ['pu' => null, 'payment' => null, 'interest' => null, 'note' => 'Valor da planilha não fornecido.'],
            '2026-08-31' => ['pu' => '1011.29612200', 'payment' => null, 'interest' => '11.29612200', 'note' => null],
        ];
    }

    /** Datas com valor de PU efetivamente observado. @return list<string> */
    public static function datesWithObservedPu(): array
    {
        return array_keys(array_filter(
            self::rows(),
            static fn (array $row): bool => $row['pu'] !== null,
        ));
    }

    /** Datas ainda sem valor lido na referência. @return list<string> */
    public static function datesWithoutObservedValue(): array
    {
        return array_keys(array_filter(
            self::rows(),
            static fn (array $row): bool => $row['pu'] === null,
        ));
    }

    /**
     * Decomposição de fatores observada em 31/08/2026 -- a única linha em que a
     * referência expôs os estágios intermediários, e por isso a única evidência
     * direta do PIPELINE do legado.
     *
     * @return array{index_factor_accumulated:string, spread_factor_displayed:string, combined_factor:string, interest:string}
     */
    public static function august31FactorBreakdown(): array
    {
        return [
            'index_factor_accumulated' => '1.0077946312497466',
            // EXIBIDO pela planilha em 9 casas. Não é o valor que ela multiplica:
            // ver `PuLegacyCompatibilityTest`.
            'spread_factor_displayed' => '1.003474409',
            'combined_factor' => '1.0112961217360983',
            'interest' => '11.29612200',
        ];
    }

    /** Juros do Nimbus na mesma data, no perfil contratual. */
    public const AUGUST_31_CONTRACTUAL_INTEREST = '11.29612100';
}
