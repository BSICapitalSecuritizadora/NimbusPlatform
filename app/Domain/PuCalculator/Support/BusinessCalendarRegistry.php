<?php

namespace App\Domain\PuCalculator\Support;

use InvalidArgumentException;

final class BusinessCalendarRegistry
{
    /**
     * Alias legado preservado para compatibilidade. Ele continua consultando os próprios dados `B3`
     * e NÃO é redirecionado silenciosamente. A migração dos consumidores exige revisão contratual.
     */
    public const LEGACY_B3 = 'B3';

    public const BR_BANKING_ANBIMA = 'BR_BANKING_ANBIMA';

    public const B3_LISTED_TRADING = 'B3_LISTED_TRADING';

    public const BR_NATIONAL_HOLIDAYS = 'BR_NATIONAL_HOLIDAYS';

    /**
     * Calendário financeiro consolidado. A semântica pertence à REGRA financeira — dias úteis aplicáveis
     * às operações do mercado financeiro, nos termos da Resolução CMN 4.880/2020 — e não à entidade que
     * publica a lista. ANBIMA e FEBRABAN entram como FONTES de evidência reconciliadas, jamais como
     * definição. Não é alias de `BR_BANKING_ANBIMA`: uma data só vira decisão aqui após reconciliação
     * governada, e feriados estaduais/municipais nunca são incorporados.
     */
    public const BR_FINANCIAL_MARKET = 'BR_FINANCIAL_MARKET';

    /**
     * Calendário da curva oficial de PU (decisão de 2026-09-28): a curva segue os
     * dias úteis com que o mercado calcula e a B3 liquida, e não a definição
     * literal de Dia Útil de cada Termo. O calendário do contrato fica só para
     * comparação (`PuContractCalendarComparisonService`).
     *
     * Hoje é o calendário bancário ANBIMA. O consolidado `BR_FINANCIAL_MARKET`
     * (ANBIMA × FEBRABAN) é o destino, mas só cobre os anos que a FEBRABAN já
     * publicou -- em 2026-09-28, apenas 2026, sem nenhum conflito com a ANBIMA --,
     * e a curva e o cronograma de eventos precisam do calendário até o vencimento.
     */
    public const MARKET_CALENDAR = self::BR_BANKING_ANBIMA;

    /**
     * @return array<string, array{label:string, meaning:string, legacy:bool, legacy_alias_of:?string, accepts_anbima:bool}>
     */
    public static function definitions(): array
    {
        return [
            self::LEGACY_B3 => [
                'label' => 'B3 (legado — semântica ANBIMA atual)',
                'meaning' => 'Código legado mantido sem remapeamento para preservar cálculos existentes. Deve ser eliminado após revisão individual dos consumidores.',
                'legacy' => true,
                'legacy_alias_of' => self::BR_BANKING_ANBIMA,
                'accepts_anbima' => true,
            ],
            self::BR_BANKING_ANBIMA => [
                'label' => 'ANBIMA — calendário bancário',
                'meaning' => 'Calendário bancário baseado na publicação de feriados bancários da ANBIMA.',
                'legacy' => false,
                'legacy_alias_of' => null,
                'accepts_anbima' => true,
            ],
            self::B3_LISTED_TRADING => [
                'label' => 'B3 — sessões de negociação',
                'meaning' => 'Calendário de sessões do mercado listado da B3. Não recebe importações ANBIMA.',
                'legacy' => false,
                'legacy_alias_of' => null,
                'accepts_anbima' => false,
            ],
            self::BR_FINANCIAL_MARKET => [
                'label' => 'Mercado financeiro brasileiro — calendário consolidado',
                'meaning' => 'Dias úteis aplicáveis às operações do mercado financeiro brasileiro. As decisões são materializadas a partir de fontes financeiras reconhecidas (ANBIMA e FEBRABAN) mediante reconciliação auditável, e não a partir de uma única publicação. Não inclui automaticamente feriados estaduais ou municipais, nem expediente especial de agência (quarta-feira de cinzas, último dia útil do ano).',
                'legacy' => false,
                'legacy_alias_of' => null,
                'accepts_anbima' => false,
            ],
            self::BR_NATIONAL_HOLIDAYS => [
                'label' => 'Feriados Nacionais — Brasil',
                'meaning' => 'Sábados, domingos e feriados de âmbito nacional instituídos por legislação federal. Não inclui automaticamente feriados bancários, Carnaval, Corpus Christi, feriados locais ou sessões B3.',
                'legacy' => false,
                'legacy_alias_of' => null,
                'accepts_anbima' => false,
            ],
        ];
    }

    /**
     * Texto da política de calendário de mercado, usado como evidência da escolha
     * do calendário da curva.
     */
    public static function marketCalendarPolicy(?string $contractCalendarCode = null): string
    {
        $contract = filled($contractCalendarCode)
            ? sprintf('O calendário do Termo (%s) fica só para comparação.', self::label((string) $contractCalendarCode))
            : 'O calendário do Termo fica só para comparação.';

        return sprintf(
            'Curva oficial pelo calendário de mercado (%s), o mesmo com que o mercado conta dias úteis e a B3 liquida. %s Decisão da securitizadora de 28/09/2026.',
            self::label(self::MARKET_CALENDAR),
            $contract,
        );
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::definitions())
            ->mapWithKeys(static fn (array $definition, string $code): array => [$code => $definition['label']])
            ->all();
    }

    public static function normalize(string $calendarCode): string
    {
        return strtoupper(trim($calendarCode));
    }

    public static function ensureKnown(string $calendarCode): string
    {
        $normalized = self::normalize($calendarCode);

        if (! array_key_exists($normalized, self::definitions())) {
            throw new InvalidArgumentException(sprintf(
                'Calendário [%s] não suportado. Use %s.',
                $calendarCode,
                implode(', ', array_keys(self::definitions())),
            ));
        }

        return $normalized;
    }

    public static function acceptsAnbima(string $calendarCode): bool
    {
        $normalized = self::ensureKnown($calendarCode);

        return self::definitions()[$normalized]['accepts_anbima'];
    }

    public static function label(string $calendarCode): string
    {
        $normalized = self::normalize($calendarCode);

        return self::definitions()[$normalized]['label'] ?? $normalized;
    }

    public static function meaning(string $calendarCode): string
    {
        $normalized = self::normalize($calendarCode);

        return self::definitions()[$normalized]['meaning'] ?? 'Calendário não catalogado.';
    }
}
