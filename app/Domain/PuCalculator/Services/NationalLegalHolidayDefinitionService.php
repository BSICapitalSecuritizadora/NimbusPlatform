<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Models\BusinessCalendarLegalRule;
use Carbon\CarbonImmutable;

class NationalLegalHolidayDefinitionService
{
    public const SOURCE_REVISION = 'federal-national-holidays-v1';

    public const MINIMUM_SUPPORTED_YEAR = 1991;

    /**
     * O limite de 1991 evita projetar incorretamente a regra de antecipação prevista pela Lei 7.320/1985,
     * revogada em 30/10/1990. Essa faixa histórica exige materializador específico antes de ser coberta.
     *
     * @return list<array{rule_key:string,rule_type:string,name:string,month:?int,day:?int,effective_from:string,effective_until:?string,norm_identification:string,article_reference:string,source_url:string,notes:string}>
     */
    public function definitions(): array
    {
        return [
            $this->fixedRule('new_year_1949', 'Confraternização Universal', 1, 1, '1949-04-13', null, 'Lei nº 662/1949', 'Art. 1º', 'https://www.planalto.gov.br/ccivil_03/leis/l0662.htm'),
            $this->fixedRule('tiradentes_1950', 'Tiradentes', 4, 21, '1950-12-12', '2002-12-19', 'Lei nº 1.266/1950', 'Art. 3º', 'https://www.planalto.gov.br/ccivil_03/leis/l1266.htm'),
            $this->fixedRule('tiradentes_2002', 'Tiradentes', 4, 21, '2002-12-20', null, 'Lei nº 10.607/2002', 'Art. 1º', 'https://www.planalto.gov.br/ccivil_03/leis/2002/l10607.htm'),
            $this->fixedRule('labour_day_1949', 'Dia Mundial do Trabalho', 5, 1, '1949-04-13', null, 'Lei nº 662/1949', 'Art. 1º', 'https://www.planalto.gov.br/ccivil_03/leis/l0662.htm'),
            $this->fixedRule('independence_1949', 'Independência do Brasil', 9, 7, '1949-04-13', null, 'Lei nº 662/1949', 'Art. 1º', 'https://www.planalto.gov.br/ccivil_03/leis/l0662.htm'),
            $this->fixedRule('our_lady_aparecida_1980', 'Nossa Senhora Aparecida', 10, 12, '1980-07-01', null, 'Lei nº 6.802/1980', 'Art. 1º', 'https://www.planalto.gov.br/ccivil_03/leis/l6802.htm'),
            $this->fixedRule('all_souls_2002', 'Finados', 11, 2, '2002-12-20', null, 'Lei nº 10.607/2002', 'Art. 1º', 'https://www.planalto.gov.br/ccivil_03/leis/2002/l10607.htm'),
            $this->fixedRule('republic_1949', 'Proclamação da República', 11, 15, '1949-04-13', null, 'Lei nº 662/1949', 'Art. 1º', 'https://www.planalto.gov.br/ccivil_03/leis/l0662.htm'),
            $this->fixedRule('black_consciousness_2023', 'Dia Nacional de Zumbi e da Consciência Negra', 11, 20, '2023-12-22', null, 'Lei nº 14.759/2023', 'Art. 1º', 'https://www.planalto.gov.br/ccivil_03/_ato2023-2026/2023/lei/l14759.htm'),
            $this->fixedRule('christmas_1949', 'Natal', 12, 25, '1949-04-13', null, 'Lei nº 662/1949', 'Art. 1º', 'https://www.planalto.gov.br/ccivil_03/leis/l0662.htm'),
            [
                'rule_key' => 'federal_and_local_scope_1995',
                'rule_type' => BusinessCalendarLegalRule::TYPE_SCOPE_DEFINITION,
                'name' => 'Âmbito federal, estadual, municipal e religioso',
                'month' => null,
                'day' => null,
                'effective_from' => '1995-09-13',
                'effective_until' => null,
                'norm_identification' => 'Lei nº 9.093/1995',
                'article_reference' => 'Arts. 1º e 2º',
                'source_url' => 'https://www.planalto.gov.br/ccivil_03/leis/l9093.htm',
                'notes' => 'Distingue feriados civis declarados por lei federal dos feriados religiosos declarados por lei municipal; não nacionaliza automaticamente a Sexta-Feira da Paixão.',
            ],
            [
                'rule_key' => 'monday_observance_1985_1990',
                'rule_type' => BusinessCalendarLegalRule::TYPE_HISTORICAL_OBSERVANCE,
                'name' => 'Antecipação histórica de comemoração para segunda-feira',
                'month' => null,
                'day' => null,
                'effective_from' => '1985-06-12',
                'effective_until' => '1990-10-29',
                'norm_identification' => 'Lei nº 7.320/1985',
                'article_reference' => 'Art. 1º',
                'source_url' => 'https://www.planalto.gov.br/ccivil_03/leis/l7320.htm',
                'notes' => 'Regime histórico de antecipação. A projeção desta faixa não é suportada pelo materializador fixo atual.',
            ],
            [
                'rule_key' => 'repeal_monday_observance_1990',
                'rule_type' => BusinessCalendarLegalRule::TYPE_HISTORICAL_OBSERVANCE,
                'name' => 'Revogação da antecipação para segunda-feira',
                'month' => null,
                'day' => null,
                'effective_from' => '1990-10-30',
                'effective_until' => null,
                'norm_identification' => 'Lei nº 8.087/1990',
                'article_reference' => 'Arts. 1º e 2º',
                'source_url' => 'https://www.planalto.gov.br/ccivil_03/leis/l8087.htm',
                'notes' => 'Revoga a Lei nº 7.320/1985 e delimita o início seguro da projeção fixa a partir de 1991.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fixedHolidayDefinitionsForYear(int $year): array
    {
        return collect($this->definitions())
            ->where('rule_type', BusinessCalendarLegalRule::TYPE_FIXED_NATIONAL_HOLIDAY)
            ->filter(function (array $definition) use ($year): bool {
                $occurrence = CarbonImmutable::create($year, (int) $definition['month'], (int) $definition['day']);
                $effectiveFrom = CarbonImmutable::parse($definition['effective_from']);
                $effectiveUntil = filled($definition['effective_until'])
                    ? CarbonImmutable::parse((string) $definition['effective_until'])
                    : null;

                return $occurrence->gte($effectiveFrom)
                    && ($effectiveUntil === null || $occurrence->lte($effectiveUntil));
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $definition */
    public function fingerprint(array $definition): string
    {
        return hash('sha256', (string) json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<string>
     */
    public function officialSourceUrls(): array
    {
        return collect($this->definitions())
            ->pluck('source_url')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{rule_key:string,rule_type:string,name:string,month:int,day:int,effective_from:string,effective_until:?string,norm_identification:string,article_reference:string,source_url:string,notes:string}
     */
    private function fixedRule(
        string $ruleKey,
        string $name,
        int $month,
        int $day,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $normIdentification,
        string $articleReference,
        string $sourceUrl,
    ): array {
        return [
            'rule_key' => $ruleKey,
            'rule_type' => BusinessCalendarLegalRule::TYPE_FIXED_NATIONAL_HOLIDAY,
            'name' => $name,
            'month' => $month,
            'day' => $day,
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
            'norm_identification' => $normIdentification,
            'article_reference' => $articleReference,
            'source_url' => $sourceUrl,
            'notes' => sprintf('%s, %s. Ocorrência anual fixa, respeitada a vigência.', $normIdentification, $articleReference),
        ];
    }
}
