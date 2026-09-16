<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\FinancialMarketCalendarMaterializationResult;
use App\Domain\PuCalculator\Enums\CalendarSourceReconciliationStatus;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Converte a evidência reconciliada de ANBIMA e FEBRABAN em decisões diárias explícitas do calendário
 * financeiro consolidado, para um ano inteiro de cada vez.
 *
 * O calendário usa `explicit_official_decisions`: sem uma linha em `business_calendar_dates`, qualquer
 * consulta levanta exceção em vez de supor que segunda a sexta é dia útil. Essa proteção é o motivo de
 * a materialização existir — e é por isso que ela NÃO pode ser substituída por
 * `weekday_with_official_exceptions`. O que se materializa aqui é a afirmação de que o ANO foi coberto
 * pelas duas fontes; só então um dia útil comum pode ser declarado útil por regra.
 *
 * A diferença em relação a {@see NationalLegalHolidayMaterializationService} é essa: lá a regra-base de
 * segunda a sexta é a política do calendário, e só as exceções são materializadas. Aqui todos os 365
 * (ou 366) dias recebem linha, porque nada pode ser inferido.
 *
 * Três portões precedem qualquer escrita, e nenhum deles é negociável:
 *
 * 1. a ANBIMA precisa ter cobertura `complete` do ano na governança já existente;
 * 2. a FEBRABAN precisa ter uma execução de importação real e bem-sucedida do ano — o importador recusa
 *    anos que a fonte não publica, então uma execução bem-sucedida é, ela própria, a prova de completude;
 * 3. a reconciliação do ano não pode conter divergência nem decisão de fonte única.
 *
 * Bloqueio é integral: ou o ano inteiro é materializado, ou nada é escrito.
 */
final class FinancialMarketCalendarMaterializationService
{
    public const SOURCE = 'financial_market_reconciliation';

    public const ORIGIN_RECONCILED_HOLIDAY = 'reconciled_holiday';

    public const ORIGIN_WEEKEND_RULE = 'weekend_rule';

    public const ORIGIN_COVERED_YEAR_RULE = 'covered_year_rule';

    private const MIN_YEAR = 1990;

    private const MAX_YEAR = 2200;

    public function __construct(
        private readonly BusinessCalendarSourceReconciliationService $reconciliation,
        private readonly BusinessCalendarYearService $calendarYears,
        private readonly BusinessCalendarRevisionService $revisions,
        private readonly BusinessDayCalendarService $businessDayCalendar,
    ) {}

    public function materialize(
        int $year,
        bool $dryRun = false,
        ?int $materializedByUserId = null,
    ): FinancialMarketCalendarMaterializationResult {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidArgumentException(sprintf(
                'Ano %d fora da faixa suportada (%d–%d).',
                $year,
                self::MIN_YEAR,
                self::MAX_YEAR,
            ));
        }

        $calendarCode = BusinessCalendarRegistry::BR_FINANCIAL_MARKET;
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0);
        $to = $from->endOfYear()->startOfDay();

        $result = new FinancialMarketCalendarMaterializationResult(
            calendarCode: $calendarCode,
            year: $year,
            dryRun: $dryRun,
        );
        $result->totalDays = (int) $from->diffInDays($to) + 1;

        $reconciliation = $this->reconciliation->reconcile($from, $to);
        $result->coverage = $reconciliation['coverage'];

        $holidays = $this->assertSourcesAgree($result, $reconciliation);
        $this->assertAnbimaCoverage($result, $year);
        $this->assertFebrabanCoverage($result, $year);

        if ($result->blocked) {
            return $result;
        }

        $decisions = $this->decideYear($from, $to, $holidays);
        $this->tally($result, $decisions);
        $result->checksum = $this->checksum($year, $decisions);

        if ($dryRun) {
            $this->projectWrites($result, $calendarCode, $decisions);
            $coverage = $this->calendarYears->coverage($calendarCode, $year);
            $result->yearStatus = $coverage['status'];
            $result->revision = $coverage['revision'];

            return $result;
        }

        $this->persist($result, $calendarCode, $year, $decisions);

        return $result;
    }

    /**
     * Portão 1 — a reconciliação do ano precisa ser inequívoca.
     *
     * `confirmed` é a única classe que vira decisão. `conflict` e `source_only_*` bloqueiam o ano
     * inteiro: não existe política governada para decidir por uma fonte só, e inventar uma aqui seria
     * exatamente o desempate silencioso que a reconciliação foi feita para impedir.
     *
     * Observações de expediente especial (quarta-feira de cinzas, último dia útil do ano) aparecem na
     * reconciliação sem evidência de mercado. Elas NÃO bloqueiam e NÃO viram feriado: a fonte diz que o
     * mercado opera nesses dias, apenas sem atendimento ao público. Elas seguem para a regra comum.
     *
     * @param  array<string, mixed>  $reconciliation
     * @return array<string, array{name:string,evidence:list<string>}> feriados confirmados por data
     */
    private function assertSourcesAgree(
        FinancialMarketCalendarMaterializationResult $result,
        array $reconciliation,
    ): array {
        $holidays = [];

        foreach ($reconciliation['rows'] as $row) {
            $status = $row['status'];
            $hasMarketEvidence = $row['anbima'] !== null || $row['febraban'] !== null;

            if ($row['special_hours'] !== null) {
                $result->specialHoursObserved++;
            }

            if (! $hasMarketEvidence) {
                // Linha originada apenas de expediente especial: não é decisão de mercado pendente.
                continue;
            }

            match ($status) {
                CalendarSourceReconciliationStatus::Confirmed->value => $holidays[$row['date']] = [
                    'name' => $row['anbima']['name'] ?? $row['febraban']['name'] ?? 'Feriado financeiro',
                    'evidence' => $row['evidence'],
                ],
                CalendarSourceReconciliationStatus::Conflict->value => $result->conflicts++,
                CalendarSourceReconciliationStatus::SourceOnlyAnbima->value,
                CalendarSourceReconciliationStatus::SourceOnlyFebraban->value => $result->sourceOnly++,
                default => $result->unknown++,
            };

            if ($status !== CalendarSourceReconciliationStatus::Confirmed->value) {
                $result->blockedDates[] = [
                    'date' => $row['date'],
                    'status' => $status,
                    'status_label' => $row['status_label'],
                    'evidence' => $row['evidence'],
                ];
            }
        }

        if ($result->conflicts > 0) {
            $result->block(sprintf(
                'Materialização bloqueada: %d divergência(s) entre ANBIMA e FEBRABAN exigem revisão humana. Nenhuma fonte é escolhida automaticamente.',
                $result->conflicts,
            ));
        }

        if ($result->sourceOnly > 0) {
            $result->block(sprintf(
                'Materialização bloqueada: %d data(s) são afirmadas por apenas uma das fontes. Não existe política governada para decidir por fonte única.',
                $result->sourceOnly,
            ));
        }

        if ($result->unknown > 0) {
            $result->block(sprintf(
                'Materialização bloqueada: %d data(s) com evidência retratada por ambas as fontes e sem decisão resultante.',
                $result->unknown,
            ));
        }

        return $holidays;
    }

    /**
     * Portão 2 — cobertura ANBIMA pela governança anual já existente.
     *
     * `coverage_status === 'complete'` só é verdadeiro quando o ano tem fonte oficial documentada, com
     * checksum, e uma execução aplicada sem conflitos, remoções ou erros. Reaproveitar esse veredito
     * evita criar uma segunda noção de "ano coberto" divergente da que o resto do módulo usa.
     */
    private function assertAnbimaCoverage(FinancialMarketCalendarMaterializationResult $result, int $year): void
    {
        $coverage = $this->calendarYears->coverage(BusinessCalendarRegistry::BR_BANKING_ANBIMA, $year);
        $result->coverage['anbima_year'] = $coverage;

        if ($coverage['coverage_status'] === 'complete') {
            return;
        }

        $result->block(sprintf(
            'Materialização bloqueada: a cobertura ANBIMA de %d está em "%s". Importe e confirme %s/%d antes de materializar.',
            $year,
            $coverage['coverage_status'],
            BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            $year,
        ));
    }

    /**
     * Portão 3 — cobertura FEBRABAN pela execução de importação.
     *
     * O importador FEBRABAN recusa anos que a fonte não publica: o endpoint responde HTTP 200 para
     * qualquer ano, devolvendo só os feriados de data fixa, e a guarda de datas móveis transforma isso
     * numa execução `failed`. Uma execução real, bem-sucedida e sem erros é, portanto, a prova de que o
     * ano veio completo — e é a mesma prova que o caso de 2030 derruba.
     */
    private function assertFebrabanCoverage(FinancialMarketCalendarMaterializationResult $result, int $year): void
    {
        $run = BusinessCalendarImportRun::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->where('source', FebrabanHolidayImporter::SOURCE_MARKET)
            ->where('year', $year)
            ->where('dry_run', false)
            ->where('result', BusinessCalendarImportRun::RESULT_SUCCEEDED)
            ->latest('started_at')
            ->latest('id')
            ->first();

        $result->coverage['febraban_run_id'] = $run?->id;
        $result->coverage['febraban_status'] = $run instanceof BusinessCalendarImportRun ? 'complete' : 'none';

        if ($run instanceof BusinessCalendarImportRun) {
            return;
        }

        $result->block(sprintf(
            'Materialização bloqueada: não há importação FEBRABAN bem-sucedida de %d. Execute pu:holidays:import-febraban --year=%d antes de materializar.',
            $year,
            $year,
        ));
    }

    /**
     * Uma decisão explícita para cada dia do ano. Nada aqui consulta a rede: só a evidência já persistida.
     *
     * @param  array<string, array{name:string,evidence:list<string>}>  $holidays
     * @return array<string, array{is_business_day:bool,description:string,origin:string,evidence:list<string>}>
     */
    private function decideYear(CarbonImmutable $from, CarbonImmutable $to, array $holidays): array
    {
        $decisions = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $dateKey = $date->toDateString();
            $holiday = $holidays[$dateKey] ?? null;

            if ($holiday !== null) {
                $decisions[$dateKey] = [
                    'is_business_day' => false,
                    'description' => $holiday['name'],
                    'origin' => self::ORIGIN_RECONCILED_HOLIDAY,
                    'evidence' => $holiday['evidence'],
                ];

                continue;
            }

            if ($date->isWeekend()) {
                $decisions[$dateKey] = [
                    'is_business_day' => false,
                    'description' => $date->isSaturday() ? 'Sábado' : 'Domingo',
                    'origin' => self::ORIGIN_WEEKEND_RULE,
                    'evidence' => [],
                ];

                continue;
            }

            $decisions[$dateKey] = [
                'is_business_day' => true,
                'description' => 'Dia útil de mercado: nenhuma das fontes reconciliadas afirma feriado nesta data.',
                'origin' => self::ORIGIN_COVERED_YEAR_RULE,
                'evidence' => [],
            ];
        }

        return $decisions;
    }

    /**
     * @param  array<string, array{is_business_day:bool,description:string,origin:string,evidence:list<string>}>  $decisions
     */
    private function tally(FinancialMarketCalendarMaterializationResult $result, array $decisions): void
    {
        foreach ($decisions as $decision) {
            match ($decision['origin']) {
                self::ORIGIN_RECONCILED_HOLIDAY => $result->financialHolidays++,
                self::ORIGIN_WEEKEND_RULE => $result->weekendDays++,
                default => $result->businessDays++,
            };
        }

        $result->nonBusinessDays = $result->financialHolidays + $result->weekendDays;
    }

    /**
     * @param  array<string, array{is_business_day:bool,description:string,origin:string,evidence:list<string>}>  $decisions
     */
    private function checksum(int $year, array $decisions): string
    {
        $manifest = [
            'calendar_code' => BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
            'year' => $year,
            'policy' => 'explicit_official_decisions',
            'norm_reference' => FebrabanHolidayImporter::NORM_REFERENCE,
            'decisions' => array_map(
                static fn (array $decision): array => [
                    'business' => $decision['is_business_day'],
                    'origin' => $decision['origin'],
                    'description' => $decision['description'],
                ],
                $decisions,
            ),
        ];

        return hash('sha256', (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Contabiliza, sem escrever, o efeito que a materialização teria.
     *
     * @param  array<string, array{is_business_day:bool,description:string,origin:string,evidence:list<string>}>  $decisions
     */
    private function projectWrites(
        FinancialMarketCalendarMaterializationResult $result,
        string $calendarCode,
        array $decisions,
    ): void {
        $existing = $this->existingDates($calendarCode, array_keys($decisions));

        foreach ($decisions as $dateKey => $decision) {
            $current = $existing[$dateKey] ?? null;

            if (! $current instanceof BusinessCalendarDate) {
                $result->rowsCreated++;

                continue;
            }

            if ($current->data_origin === 'manual_override') {
                $result->rowsPreservedByOverride++;

                continue;
            }

            $this->matches($current, $decision) ? $result->rowsUnchanged++ : $result->rowsUpdated++;
        }
    }

    /**
     * @param  array<string, array{is_business_day:bool,description:string,origin:string,evidence:list<string>}>  $decisions
     */
    private function persist(
        FinancialMarketCalendarMaterializationResult $result,
        string $calendarCode,
        int $year,
        array $decisions,
    ): void {
        $changedYear = null;

        Cache::lock($this->calendarYears->lockKey($calendarCode), 180)
            ->block((int) config('pu_calculator.business_calendar.lock_wait_seconds', 15), function () use (
                $result,
                $calendarCode,
                $year,
                $decisions,
                &$changedYear,
            ): void {
                DB::transaction(function () use ($result, $calendarCode, $year, $decisions, &$changedYear): void {
                    $calendarYear = $this->calendarYears->findOrCreateForUpdate($calendarCode, $year, [
                        'source' => self::SOURCE,
                        'source_is_official' => true,
                    ]);
                    $sourceDocument = $this->sourceDocument($year);
                    $manifestChanged = ! hash_equals((string) ($calendarYear->checksum ?? ''), (string) $result->checksum);

                    if ($manifestChanged) {
                        $calendarYear->fill([
                            'source' => self::SOURCE,
                            'source_is_official' => true,
                            'source_document' => $sourceDocument,
                            'source_revision' => FebrabanHolidayImporter::NORM_REFERENCE,
                            'checksum' => $result->checksum,
                            'revision' => ((int) $calendarYear->revision) + 1,
                        ]);

                        // Uma decisão já confirmada nunca é sobrescrita em silêncio: o ano cai para
                        // `stale` e a revisão avança, que é o mecanismo que o módulo já usa para exigir
                        // nova revisão humana e invalidar o cache de quem já leu o calendário.
                        if ($calendarYear->status === BusinessCalendarYear::STATUS_CONFIRMED) {
                            $calendarYear->status = BusinessCalendarYear::STATUS_STALE;
                        }

                        $calendarYear->save();
                        $changedYear = $calendarYear;
                    }

                    $existing = $this->existingDates($calendarCode, array_keys($decisions));

                    foreach ($decisions as $dateKey => $decision) {
                        $current = $existing[$dateKey] ?? null;

                        if ($current instanceof BusinessCalendarDate && $current->data_origin === 'manual_override') {
                            $result->rowsPreservedByOverride++;

                            continue;
                        }

                        if ($current instanceof BusinessCalendarDate && $this->matches($current, $decision)) {
                            $result->rowsUnchanged++;

                            continue;
                        }

                        $row = $current ?? new BusinessCalendarDate([
                            'calendar_code' => $calendarCode,
                            'calendar_date' => $dateKey,
                        ]);
                        $row->fill([
                            'business_calendar_year_id' => $calendarYear->id,
                            'is_business_day' => $decision['is_business_day'],
                            'description' => $decision['description'],
                            'data_origin' => $decision['origin'],
                            'source' => self::SOURCE,
                            'source_is_official' => true,
                            'source_document' => $this->dateDocument($decision),
                            'source_revision' => FebrabanHolidayImporter::NORM_REFERENCE,
                            'revision' => (int) $calendarYear->revision,
                        ]);

                        $current instanceof BusinessCalendarDate ? $result->rowsUpdated++ : $result->rowsCreated++;
                        $row->save();
                    }

                    $result->yearStatus = (string) $calendarYear->status;
                    $result->revision = (int) $calendarYear->revision;
                });
            });

        if ($changedYear instanceof BusinessCalendarYear) {
            $this->revisions->publish($changedYear->fresh());
        }

        $this->businessDayCalendar->flushCache();
    }

    /**
     * @param  array{is_business_day:bool,description:string,origin:string,evidence:list<string>}  $decision
     */
    private function matches(BusinessCalendarDate $current, array $decision): bool
    {
        return (bool) $current->is_business_day === $decision['is_business_day']
            && (string) $current->description === $decision['description']
            && (string) $current->data_origin === $decision['origin']
            && (string) $current->source === self::SOURCE;
    }

    /**
     * @param  array{is_business_day:bool,description:string,origin:string,evidence:list<string>}  $decision
     */
    private function dateDocument(array $decision): string
    {
        if ($decision['evidence'] === []) {
            return sprintf(
                'Reconciliação ANBIMA × FEBRABAN (%s). Ano com cobertura comprovada nas duas fontes.',
                FebrabanHolidayImporter::NORM_REFERENCE,
            );
        }

        return sprintf(
            'Reconciliação ANBIMA × FEBRABAN (%s). Evidência: %s.',
            FebrabanHolidayImporter::NORM_REFERENCE,
            implode(' + ', $decision['evidence']),
        );
    }

    private function sourceDocument(int $year): string
    {
        return sprintf(
            'Materialização governada de %s/%d a partir da reconciliação ANBIMA × FEBRABAN, nos termos da %s.',
            BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
            $year,
            FebrabanHolidayImporter::NORM_REFERENCE,
        );
    }

    /**
     * @param  list<string>  $dateKeys
     * @return array<string, BusinessCalendarDate>
     */
    private function existingDates(string $calendarCode, array $dateKeys): array
    {
        return BusinessCalendarDate::query()
            ->where('calendar_code', $calendarCode)
            ->whereDate('calendar_date', '>=', $dateKeys[0])
            ->whereDate('calendar_date', '<=', $dateKeys[count($dateKeys) - 1])
            ->get()
            ->keyBy(fn (BusinessCalendarDate $date): string => CarbonImmutable::instance($date->calendar_date)->toDateString())
            ->all();
    }
}
