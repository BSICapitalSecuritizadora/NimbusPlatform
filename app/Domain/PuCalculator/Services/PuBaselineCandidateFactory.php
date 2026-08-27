<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\DTOs\PuBaselineCandidate;
use App\Domain\PuCalculator\DTOs\PuBaselineRequirement;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementCategory;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementSeverity;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;
use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\DTOs\LegalInstruments\ConsolidatedFieldData;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldValueType;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentField;
use App\Services\LegalInstruments\InstrumentPositionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

final class PuBaselineCandidateFactory
{
    private const PENDING = 'PENDING';

    /**
     * Campos do candidato que a engine consome mas `emission_pu_parameters`
     * não armazena.
     *
     * `index_percentage` é o único caso hoje: a tabela não possui a coluna e a
     * engine de CDI compõe o fator diário direto da Taxa DI (100% do índice),
     * sem multiplicador de percentual. O valor permanece no candidato porque é
     * evidência contratual que precisa ser lida e conferida, mas nunca pode ser
     * apresentado como pronto para persistência — não há onde gravá-lo. Quando
     * o contrato comprovar um percentual diferente de 100%, quem bloqueia é a
     * capacidade de engine, não este mapa.
     *
     * @var list<string>
     */
    private const NON_PERSISTABLE_CANDIDATE_FIELDS = ['index_percentage'];

    /** @var list<LegalInstrumentFieldKey> */
    private const PU_FIELD_KEYS = [
        LegalInstrumentFieldKey::IssueDate,
        LegalInstrumentFieldKey::Indexer,
        LegalInstrumentFieldKey::IndexPercentage,
        LegalInstrumentFieldKey::Spread,
        LegalInstrumentFieldKey::BusinessDayBasis,
        LegalInstrumentFieldKey::DayCountRule,
        LegalInstrumentFieldKey::BusinessDayDefinition,
        LegalInstrumentFieldKey::CalendarCode,
        LegalInstrumentFieldKey::IndexRateLookupMode,
        LegalInstrumentFieldKey::IndexRateLagBusinessDays,
        LegalInstrumentFieldKey::InitialUnitValue,
        LegalInstrumentFieldKey::MaturityDate,
        LegalInstrumentFieldKey::PaymentSchedule,
        LegalInstrumentFieldKey::FirstInterestPaymentDate,
        LegalInstrumentFieldKey::InterestPaymentFrequency,
        LegalInstrumentFieldKey::Amortization,
        LegalInstrumentFieldKey::PaymentConvention,
        LegalInstrumentFieldKey::FirstCouponPreIntegralizationPremiumEnabled,
        LegalInstrumentFieldKey::FirstCouponPreIntegralizationBusinessDays,
        LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplyIndexFactor,
        LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplySpreadFactor,
    ];

    /**
     * Subconjunto que só existe quando alguém estrutura a configuração de PU.
     *
     * Ficam de fora `issue_date`, `maturity_date`, `indexer`, `spread`,
     * `payment_schedule` e `amortization`: o extrator jurídico produz esses
     * campos em qualquer instrumento, e usá-los como gatilho ligaria o gate em
     * emissões que nunca terão curva.
     *
     * @var list<LegalInstrumentFieldKey>
     */
    private const PU_CONFIGURATION_FIELD_KEYS = [
        LegalInstrumentFieldKey::IndexPercentage,
        LegalInstrumentFieldKey::BusinessDayBasis,
        LegalInstrumentFieldKey::DayCountRule,
        LegalInstrumentFieldKey::BusinessDayDefinition,
        LegalInstrumentFieldKey::CalendarCode,
        LegalInstrumentFieldKey::IndexRateLookupMode,
        LegalInstrumentFieldKey::IndexRateLagBusinessDays,
        LegalInstrumentFieldKey::InitialUnitValue,
        LegalInstrumentFieldKey::FirstInterestPaymentDate,
        LegalInstrumentFieldKey::InterestPaymentFrequency,
        LegalInstrumentFieldKey::PaymentConvention,
        LegalInstrumentFieldKey::FirstCouponPreIntegralizationPremiumEnabled,
        LegalInstrumentFieldKey::FirstCouponPreIntegralizationBusinessDays,
        LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplyIndexFactor,
        LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplySpreadFactor,
    ];

    public function __construct(
        private readonly InstrumentPositionResolver $positionResolver,
        private readonly BusinessDayCalendar $businessDayCalendar,
    ) {}

    /**
     * A emissão possui contexto real de configuração de PU?
     *
     * `PU_FIELD_KEYS` inteiro não serve como critério: `issue_date`,
     * `maturity_date`, `indexer`, `spread` e `amortization` são extraídos pelo
     * módulo jurídico em qualquer instrumento, e usá-los faria o gate aparecer
     * em toda emissão que tenha um contrato lido. O que caracteriza contexto de
     * PU é a presença de pelo menos um campo que só existe porque alguém está
     * estruturando a configuração de cálculo (`PU_CONFIGURATION_FIELD_KEYS`) ou
     * de uma evidência de baseline, que é específica deste domínio por
     * construção.
     */
    public function supports(Emission $emission): bool
    {
        return $emission->legalInstrumentFields()
            ->whereIn('field_key', array_map(
                fn (LegalInstrumentFieldKey $key): string => $key->value,
                self::PU_CONFIGURATION_FIELD_KEYS,
            ))
            ->exists()
            || $emission->puBaselineEvidences()->exists();
    }

    public function make(
        Emission $emission,
        ?EmissionPuBaselineEvidence $curveStartEvidence,
        ?CarbonImmutable $asOf = null,
    ): PuBaselineCandidate {
        $asOf ??= CarbonImmutable::today();
        $currentVersions = $this->currentVersions($emission, $asOf);
        $resolved = collect(self::PU_FIELD_KEYS)
            ->mapWithKeys(fn (LegalInstrumentFieldKey $key): array => [
                $key->value => $this->resolveField($currentVersions->get($key->value, collect()), $key),
            ])
            ->all();

        $indexer = $this->enumValue($resolved, LegalInstrumentFieldKey::Indexer, PuIndexer::class);
        $lookupMode = $this->enumValue(
            $resolved,
            LegalInstrumentFieldKey::IndexRateLookupMode,
            PuIndexRateLookupMode::class,
        );
        $premiumEnabled = $this->booleanValue(
            $resolved[LegalInstrumentFieldKey::FirstCouponPreIntegralizationPremiumEnabled->value]['value'],
        );
        $curveStartDate = $this->evidencedDate($curveStartEvidence);
        $configuration = $this->configuration(
            $resolved,
            $indexer,
            $lookupMode,
            $premiumEnabled,
            $curveStartDate,
        );
        $calendarWindow = $this->calendarWindow($resolved, $configuration, $curveStartDate, $premiumEnabled);

        return new PuBaselineCandidate(
            configuration: $configuration,
            fields: $this->candidateFields($resolved, $configuration, $curveStartEvidence, $premiumEnabled),
            contractRequirements: $this->contractRequirements($resolved, $indexer, $premiumEnabled),
            contractualSchedule: [
                'first_interest_payment_date' => $this->dateString(
                    $resolved[LegalInstrumentFieldKey::FirstInterestPaymentDate->value]['value'],
                ),
                'interest_payment_frequency' => $this->textValue(
                    $resolved[LegalInstrumentFieldKey::InterestPaymentFrequency->value]['value'],
                ),
                'amortization' => $this->textValue(
                    $resolved[LegalInstrumentFieldKey::Amortization->value]['value'],
                ),
                'payment_convention' => $this->textValue(
                    $resolved[LegalInstrumentFieldKey::PaymentConvention->value]['value'],
                ),
            ],
            indexer: $indexer,
            lookupMode: $lookupMode,
            calendarFromDate: $calendarWindow['from'],
            curveEndDate: $this->dateValue($resolved[LegalInstrumentFieldKey::MaturityDate->value]['value']),
            calendarToDate: $calendarWindow['to'],
            calendarWindowLimitations: $calendarWindow['limitations'],
        );
    }

    /**
     * Versões vigentes de cada campo de PU na data, uma por instrumento.
     *
     * A vigência não é decidida aqui: cada instrumento é resolvido por
     * `InstrumentPositionResolver`, que é a política do projeto — versão
     * confirmada de maior `effective_date` até a data, empate desfeito pelo
     * `id`, incluindo linhas `superseded` porque foi elas que valiam antes do
     * aditamento. Assim aditamentos sucessivos NÃO viram conflito, e um
     * aditamento com vigência futura não vaza para a posição de hoje.
     *
     * O que sobra agrupado com mais de uma linha é divergência entre
     * instrumentos diferentes da mesma emissão — conflito documental
     * simultâneo, tratado em `resolveField()`.
     *
     * @return Collection<string, Collection<int, LegalInstrumentField>>
     */
    private function currentVersions(Emission $emission, CarbonImmutable $asOf): Collection
    {
        $puKeys = array_map(
            fn (LegalInstrumentFieldKey $key): string => $key->value,
            self::PU_FIELD_KEYS,
        );

        return $emission->legalInstruments()
            ->get()
            // `values()` é obrigatório: `fieldsAsOf()` devolve a coleção indexada
            // por `field_key`, e um `flatMap` que preserve essas chaves faria o
            // último instrumento sobrescrever os anteriores — o conflito
            // documental desapareceria silenciosamente.
            ->flatMap(fn (LegalInstrument $instrument): Collection => $this->positionResolver
                ->fieldsAsOf($instrument, $asOf)
                ->map(fn (ConsolidatedFieldData $field): LegalInstrumentField => $field->current)
                ->values())
            ->filter(fn (LegalInstrumentField $field): bool => $field->guarantee_id === null
                && in_array($field->field_key?->value, $puKeys, true))
            ->groupBy(fn (LegalInstrumentField $field): string => (string) $field->field_key?->value);
    }

    /**
     * Janela de calendário exigida pela emissão.
     *
     * Não é só a `issue_date`: o prêmio pré-integralização acumula Dias Úteis
     * ANTES da data inicial da curva e o lag do índice recua mais alguns, então
     * a cobertura precisa alcançar esse trecho. O recuo é medido com o próprio
     * calendário do projeto — nenhuma data é inventada. Quando o calendário
     * ainda não tem dados para executar o deslocamento, a janela permanece na
     * borda contratual e a limitação é declarada em vez de estimada; assim que
     * o ano for carregado, a avaliação seguinte alcança o trecho real.
     *
     * @param  array<string, array<string, mixed>>  $resolved
     * @param  array<string, mixed>  $configuration
     * @return array{from:?CarbonImmutable,to:?CarbonImmutable,limitations:list<string>}
     */
    private function calendarWindow(
        array $resolved,
        array $configuration,
        ?CarbonImmutable $curveStartDate,
        ?bool $premiumEnabled,
    ): array {
        $issueDate = $this->dateValue($resolved[LegalInstrumentFieldKey::IssueDate->value]['value']);
        $maturityDate = $this->dateValue($resolved[LegalInstrumentFieldKey::MaturityDate->value]['value']);
        $calendarCode = $configuration['calendar_code'];
        $from = $this->earliest($issueDate, $curveStartDate);
        $to = $maturityDate;
        $limitations = [];

        if (! is_string($calendarCode) || $calendarCode === self::PENDING) {
            return ['from' => $from, 'to' => $to, 'limitations' => $limitations];
        }

        if ($curveStartDate instanceof CarbonImmutable) {
            $businessDaysBefore = ($premiumEnabled === true
                ? max(0, (int) ($configuration['first_coupon_pre_integralization_business_days'] ?? 0))
                : 0)
                + abs(min(0, (int) ($configuration['index_rate_lag_business_days'] ?? 0)));

            if ($businessDaysBefore > 0) {
                try {
                    $from = $this->earliest($from, $this->businessDayCalendar->shiftBusinessDays(
                        $curveStartDate,
                        -$businessDaysBefore,
                        $calendarCode,
                    ));
                } catch (Throwable) {
                    $limitations[] = sprintf(
                        'A janela anterior ao início da curva (%d Dia(s) Útil(eis)) não pôde ser medida com os dados atuais de %s; a cobertura exigida ainda considera apenas o período contratual.',
                        $businessDaysBefore,
                        $calendarCode,
                    );
                }
            }
        }

        if ($maturityDate instanceof CarbonImmutable) {
            try {
                $to = $this->latest($to, $this->businessDayCalendar->nextBusinessDay($maturityDate, $calendarCode));
            } catch (Throwable) {
                $limitations[] = sprintf(
                    'O deslocamento do vencimento para o próximo Dia Útil não pôde ser medido com os dados atuais de %s; a cobertura exigida termina no vencimento contratual.',
                    $calendarCode,
                );
            }
        }

        return ['from' => $from, 'to' => $to, 'limitations' => $limitations];
    }

    private function earliest(?CarbonImmutable $left, ?CarbonImmutable $right): ?CarbonImmutable
    {
        if (! $left instanceof CarbonImmutable) {
            return $right;
        }

        return $right instanceof CarbonImmutable ? $left->min($right) : $left;
    }

    private function latest(?CarbonImmutable $left, ?CarbonImmutable $right): ?CarbonImmutable
    {
        if (! $left instanceof CarbonImmutable) {
            return $right;
        }

        return $right instanceof CarbonImmutable ? $left->max($right) : $left;
    }

    /**
     * Valor utilizável de um campo, ou o motivo pelo qual ele não existe.
     *
     * A coleção recebida já contém apenas a versão VIGENTE de cada instrumento
     * (ver `currentVersions()`), então mais de um elemento com valores
     * diferentes significa que dois instrumentos da mesma emissão afirmam
     * coisas distintas ao mesmo tempo — conflito documental, não sucessão de
     * vigências. O gate não escolhe entre eles.
     *
     * @param  Collection<int, LegalInstrumentField>  $fields
     * @return array{field:?LegalInstrumentField,value:mixed,valid:bool,conflict:bool,reason:?string}
     */
    private function resolveField(Collection $fields, LegalInstrumentFieldKey $key): array
    {
        if ($fields->isEmpty()) {
            return [
                'field' => null,
                'value' => null,
                'valid' => false,
                'conflict' => false,
                'reason' => 'Nenhuma evidência contratual confirmada foi localizada.',
            ];
        }

        $distinctValues = $fields
            ->map(fn (LegalInstrumentField $field): string => $this->comparisonValue($field))
            ->unique()
            ->values();
        $field = $fields->first();

        if ($fields->contains(fn (LegalInstrumentField $candidate): bool => $candidate->has_conflict)) {
            return [
                'field' => $field,
                'value' => null,
                'valid' => false,
                'conflict' => true,
                'reason' => sprintf(
                    'A evidência de %s está marcada como conflitante pelo módulo jurídico: %s',
                    $key->label(),
                    $fields->first(fn (LegalInstrumentField $candidate): bool => (bool) $candidate->has_conflict)
                        ?->conflict_reason
                        ?? 'o documento não altera a regra contratual por si só.',
                ),
            ];
        }

        if ($distinctValues->count() > 1) {
            return [
                'field' => $field,
                'value' => null,
                'valid' => false,
                'conflict' => true,
                'reason' => sprintf(
                    'Instrumentos vigentes da mesma emissão afirmam valores diferentes para %s (%s); o gate não escolhe entre documentos simultâneos.',
                    $key->label(),
                    $distinctValues->implode(' / '),
                ),
            ];
        }

        $value = $this->typedValue($field);

        return [
            'field' => $field,
            'value' => $value,
            'valid' => $this->valueIsValid($key, $value),
            'conflict' => false,
            'reason' => $this->valueIsValid($key, $value)
                ? null
                : sprintf('O valor confirmado para %s não possui formato utilizável.', $key->label()),
        ];
    }

    private function typedValue(LegalInstrumentField $field): mixed
    {
        return match ($field->value_type) {
            LegalInstrumentFieldValueType::Date => $field->value_date?->toDateString(),
            LegalInstrumentFieldValueType::Money,
            LegalInstrumentFieldValueType::Percentage,
            LegalInstrumentFieldValueType::Number => $field->value_numeric,
            default => $field->value,
        };
    }

    private function comparisonValue(LegalInstrumentField $field): string
    {
        $value = $this->typedValue($field);

        if (is_float($value) || is_int($value)) {
            return rtrim(rtrim(number_format((float) $value, 12, '.', ''), '0'), '.');
        }

        return mb_strtolower(trim((string) $value));
    }

    private function valueIsValid(LegalInstrumentFieldKey $key, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return match ($key) {
            LegalInstrumentFieldKey::Indexer => PuIndexer::tryFrom(mb_strtoupper((string) $value)) !== null,
            LegalInstrumentFieldKey::IndexRateLookupMode => PuIndexRateLookupMode::tryFrom(
                mb_strtolower((string) $value),
            ) !== null,
            LegalInstrumentFieldKey::IssueDate,
            LegalInstrumentFieldKey::MaturityDate,
            LegalInstrumentFieldKey::FirstInterestPaymentDate => $this->dateValue($value) !== null,
            LegalInstrumentFieldKey::FirstCouponPreIntegralizationPremiumEnabled,
            LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplyIndexFactor,
            LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplySpreadFactor => $this->booleanValue($value) !== null,
            LegalInstrumentFieldKey::BusinessDayBasis,
            LegalInstrumentFieldKey::FirstCouponPreIntegralizationBusinessDays => is_numeric($value) && (int) $value > 0,
            LegalInstrumentFieldKey::InitialUnitValue => is_numeric($value) && (float) $value > 0,
            LegalInstrumentFieldKey::IndexPercentage => $this->isUsableFraction($value),
            LegalInstrumentFieldKey::IndexRateLagBusinessDays => is_numeric($value),
            LegalInstrumentFieldKey::Spread => $this->isUsableFraction($value),
            default => filled($value),
        };
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  array<string, array<string, mixed>>  $resolved
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumValue(array $resolved, LegalInstrumentFieldKey $key, string $enum): ?object
    {
        $value = $resolved[$key->value]['value'];

        if (! is_string($value)) {
            return null;
        }

        $normalized = $key === LegalInstrumentFieldKey::Indexer
            ? mb_strtoupper(trim($value))
            : mb_strtolower(trim($value));

        return $enum::tryFrom($normalized);
    }

    /**
     * @param  array<string, array<string, mixed>>  $resolved
     * @return array<string, mixed>
     */
    private function configuration(
        array $resolved,
        ?PuIndexer $indexer,
        ?PuIndexRateLookupMode $lookupMode,
        ?bool $premiumEnabled,
        ?CarbonImmutable $curveStartDate,
    ): array {
        $requiresRates = $indexer?->requiresIndexRates() ?? true;
        $usesSpread = $indexer?->usesSpread() ?? true;

        return [
            'indexer' => $indexer?->value ?? self::PENDING,
            'index_percentage' => $indexer === PuIndexer::Cdi
                ? $this->percentage($resolved[LegalInstrumentFieldKey::IndexPercentage->value])
                : null,
            'spread_rate' => $usesSpread
                ? $this->percentage($resolved[LegalInstrumentFieldKey::Spread->value])
                : null,
            // `calculation_method` é coluna real de emission_pu_parameters, mas é
            // derivada do indexador (`PuCalculationMethod::forIndexer()`, com o
            // mesmo fallback em `resolvedCalculationMethod()`). Por isso ela não
            // exige evidência contratual própria: comprovado o indexador, o
            // método está comprovado.
            'calculation_method' => $indexer === null
                ? self::PENDING
                : PuCalculationMethod::forIndexer($indexer)->value,
            'business_day_basis' => $this->integer($resolved[LegalInstrumentFieldKey::BusinessDayBasis->value]),
            'calendar_code' => $this->upperText($resolved[LegalInstrumentFieldKey::CalendarCode->value]),
            'index_rate_lookup_mode' => $requiresRates
                ? ($lookupMode?->value ?? self::PENDING)
                : null,
            'index_rate_lag_business_days' => $requiresRates
                ? $this->integer($resolved[LegalInstrumentFieldKey::IndexRateLagBusinessDays->value])
                : null,
            'curve_start_date' => $curveStartDate?->toDateString() ?? self::PENDING,
            'curve_end_date' => $this->dateString($resolved[LegalInstrumentFieldKey::MaturityDate->value]['value'])
                ?? self::PENDING,
            'initial_unit_value' => $this->decimal(
                $resolved[LegalInstrumentFieldKey::InitialUnitValue->value],
                16,
            ),
            'first_coupon_pre_integralization_premium_enabled' => $premiumEnabled ?? self::PENDING,
            'first_coupon_pre_integralization_business_days' => $premiumEnabled === true
                ? $this->integer($resolved[LegalInstrumentFieldKey::FirstCouponPreIntegralizationBusinessDays->value])
                : null,
            'first_coupon_pre_integralization_apply_index_factor' => $premiumEnabled === true
                ? ($this->booleanValue($resolved[LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplyIndexFactor->value]['value']) ?? self::PENDING)
                : false,
            'first_coupon_pre_integralization_apply_spread_factor' => $premiumEnabled === true
                ? ($this->booleanValue($resolved[LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplySpreadFactor->value]['value']) ?? self::PENDING)
                : false,
            'legacy_projection_enabled' => false,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $resolved
     * @return array<string, PuBaselineRequirement>
     */
    private function contractRequirements(
        array $resolved,
        ?PuIndexer $indexer,
        ?bool $premiumEnabled,
    ): array {
        $requirements = [
            'contract_issue_date' => $this->contractRequirement(
                'contract_issue_date',
                'Início do período contratual',
                [LegalInstrumentFieldKey::IssueDate],
                $resolved,
            ),
            'contract_indexer' => $this->contractRequirement(
                'contract_indexer',
                'Indexador e percentual',
                array_values(array_filter([
                    LegalInstrumentFieldKey::Indexer,
                    $indexer === PuIndexer::Cdi ? LegalInstrumentFieldKey::IndexPercentage : null,
                ])),
                $resolved,
            ),
            'contract_basis' => $this->contractRequirement(
                'contract_basis',
                'Base de Dias Úteis',
                [LegalInstrumentFieldKey::BusinessDayBasis],
                $resolved,
            ),
            'contract_dup' => $this->contractRequirement(
                'contract_dup',
                'Regra de contagem',
                [LegalInstrumentFieldKey::DayCountRule],
                $resolved,
            ),
            'contract_business_day' => $this->contractRequirement(
                'contract_business_day',
                'Definição de Dia Útil e calendário',
                [LegalInstrumentFieldKey::BusinessDayDefinition, LegalInstrumentFieldKey::CalendarCode],
                $resolved,
            ),
            'contract_vnu' => $this->contractRequirement(
                'contract_vnu',
                'Valor nominal unitário inicial',
                [LegalInstrumentFieldKey::InitialUnitValue],
                $resolved,
            ),
            'contract_maturity' => $this->contractRequirement(
                'contract_maturity',
                'Vencimento',
                [LegalInstrumentFieldKey::MaturityDate],
                $resolved,
            ),
            'contract_interest_schedule' => $this->contractRequirement(
                'contract_interest_schedule',
                'Cronograma contratual de juros',
                [
                    LegalInstrumentFieldKey::PaymentSchedule,
                    LegalInstrumentFieldKey::FirstInterestPaymentDate,
                    LegalInstrumentFieldKey::InterestPaymentFrequency,
                ],
                $resolved,
            ),
            'contract_bullet' => $this->contractRequirement(
                'contract_bullet',
                'Regra contratual de amortização',
                [LegalInstrumentFieldKey::Amortization],
                $resolved,
            ),
            'contract_payment_convention' => $this->contractRequirement(
                'contract_payment_convention',
                'Convenção contratual de pagamento',
                [LegalInstrumentFieldKey::PaymentConvention],
                $resolved,
            ),
            'contract_opening_premium' => $this->contractRequirement(
                'contract_opening_premium',
                'Regra do prêmio pré-integralização',
                array_values(array_filter([
                    LegalInstrumentFieldKey::FirstCouponPreIntegralizationPremiumEnabled,
                    $premiumEnabled === true ? LegalInstrumentFieldKey::FirstCouponPreIntegralizationBusinessDays : null,
                    $premiumEnabled === true ? LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplyIndexFactor : null,
                    $premiumEnabled === true ? LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplySpreadFactor : null,
                ])),
                $resolved,
            ),
        ];

        if ($indexer?->usesSpread() ?? true) {
            $requirements['contract_spread'] = $this->contractRequirement(
                'contract_spread',
                'Spread contratual',
                [LegalInstrumentFieldKey::Spread],
                $resolved,
            );
        }

        if ($indexer?->requiresIndexRates() ?? true) {
            $requirements['contract_lookup'] = $this->contractRequirement(
                'contract_lookup',
                'Consulta e lag do índice',
                [LegalInstrumentFieldKey::IndexRateLookupMode, LegalInstrumentFieldKey::IndexRateLagBusinessDays],
                $resolved,
            );
        }

        return $requirements;
    }

    /**
     * @param  list<LegalInstrumentFieldKey>  $keys
     * @param  array<string, array<string, mixed>>  $resolved
     */
    private function contractRequirement(
        string $code,
        string $name,
        array $keys,
        array $resolved,
    ): PuBaselineRequirement {
        $invalid = collect($keys)
            ->map(fn (LegalInstrumentFieldKey $key): array => $resolved[$key->value])
            ->first(fn (array $field): bool => ! $field['valid']);
        $fields = collect($keys)
            ->map(fn (LegalInstrumentFieldKey $key): array => [
                'field' => $key->value,
                'label' => $key->label(),
                'value' => $this->displayValue($key, $resolved[$key->value]['value']),
                'source' => $this->evidence($resolved[$key->value]['field']),
            ])
            ->all();
        $satisfied = $invalid === null;

        return new PuBaselineRequirement(
            code: $code,
            name: $name,
            category: PuBaselineRequirementCategory::Contract,
            status: $satisfied
                ? PuBaselineRequirementStatus::Satisfied
                : PuBaselineRequirementStatus::Blocking,
            severity: $satisfied
                ? PuBaselineRequirementSeverity::Information
                : PuBaselineRequirementSeverity::Critical,
            reason: $satisfied
                ? sprintf('%s possui evidência contratual confirmada e rastreável.', $name)
                : (string) ($invalid['reason'] ?? sprintf('%s ainda não foi comprovado.', $name)),
            evidence: ['fields' => $fields],
            expected: 'evidência contratual confirmada',
            found: collect($fields)->pluck('value', 'field')->all(),
            blocks: ['candidate_configuration', 'numeric_homologation'],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $resolved
     * @param  array<string, mixed>  $configuration
     * @return list<array<string, mixed>>
     */
    private function candidateFields(
        array $resolved,
        array $configuration,
        ?EmissionPuBaselineEvidence $curveStartEvidence,
        ?bool $premiumEnabled,
    ): array {
        $sources = [
            'indexer' => LegalInstrumentFieldKey::Indexer,
            'index_percentage' => LegalInstrumentFieldKey::IndexPercentage,
            'spread_rate' => LegalInstrumentFieldKey::Spread,
            'calculation_method' => LegalInstrumentFieldKey::Indexer,
            'business_day_basis' => LegalInstrumentFieldKey::BusinessDayBasis,
            'calendar_code' => LegalInstrumentFieldKey::CalendarCode,
            'index_rate_lookup_mode' => LegalInstrumentFieldKey::IndexRateLookupMode,
            'index_rate_lag_business_days' => LegalInstrumentFieldKey::IndexRateLagBusinessDays,
            'curve_end_date' => LegalInstrumentFieldKey::MaturityDate,
            'initial_unit_value' => LegalInstrumentFieldKey::InitialUnitValue,
            'first_coupon_pre_integralization_premium_enabled' => LegalInstrumentFieldKey::FirstCouponPreIntegralizationPremiumEnabled,
            'first_coupon_pre_integralization_business_days' => LegalInstrumentFieldKey::FirstCouponPreIntegralizationBusinessDays,
            'first_coupon_pre_integralization_apply_index_factor' => LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplyIndexFactor,
            'first_coupon_pre_integralization_apply_spread_factor' => LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplySpreadFactor,
        ];
        $rows = collect($sources)
            ->map(function (LegalInstrumentFieldKey $key, string $candidateField) use (
                $configuration,
                $resolved,
                $premiumEnabled,
            ): array {
                $source = $resolved[$key->value];
                $notRequired = $this->fieldIsNotApplicable($candidateField, $configuration, $premiumEnabled);
                $evidence = $this->evidence($source['field']);

                return [
                    'field' => $candidateField,
                    'label' => $key->label(),
                    'value' => $configuration[$candidateField],
                    'document' => $evidence['document'] ?? ($notRequired ? 'Não aplicável' : 'Evidência pendente'),
                    'document_id' => $evidence['document_id'] ?? null,
                    'reference' => $evidence['reference'] ?? null,
                    'clause' => $evidence['clause'] ?? null,
                    'page' => $evidence['page'] ?? null,
                    'excerpt' => $evidence['excerpt'] ?? null,
                    'validation_status' => $evidence['validation_status'] ?? null,
                    'reviewer' => $evidence['reviewer'] ?? null,
                    'reviewed_at' => $evidence['reviewed_at'] ?? null,
                    'status' => $notRequired ? 'not_required' : ($source['valid'] ? 'proven' : 'blocked'),
                    'confidence' => $notRequired ? 'not_applicable' : $this->confidence($source['field']),
                    'ready_for_future_persistence' => $notRequired || ($source['valid']
                        && ! in_array($candidateField, self::NON_PERSISTABLE_CANDIDATE_FIELDS, true)),
                ];
            })
            ->values()
            ->all();
        $rows[] = $this->curveStartField($configuration['curve_start_date'], $curveStartEvidence);
        $rows[] = [
            'field' => 'legacy_projection_enabled',
            'label' => 'Projeções legadas',
            'value' => false,
            'document' => 'Controle do gate de prontidão',
            'document_id' => null,
            'reference' => 'A avaliação não cria PuHistory nem pagamentos',
            'clause' => null,
            'page' => null,
            'excerpt' => null,
            'validation_status' => 'system_control',
            'reviewer' => null,
            'reviewed_at' => null,
            'status' => 'governance_control',
            'confidence' => 'high',
            'ready_for_future_persistence' => true,
        ];

        return $rows;
    }

    /**
     * O campo do candidato é inaplicável a esta emissão?
     *
     * Inaplicável não é o mesmo que pendente. Um campo cujo valor final é
     * `null` porque o indexador não usa spread, não consome índice externo ou
     * não tem percentual não exige evidência nenhuma. Os três detalhes do
     * prêmio pré-integralização são um caso à parte: quando o contrato comprova
     * o prêmio DESABILITADO, a configuração é definitivamente `null`/`false` e
     * também não exige evidência; quando a própria regra do prêmio ainda não
     * foi comprovada, eles seguem pendentes — declarar "não aplicável" ali
     * esconderia a lacuna.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function fieldIsNotApplicable(
        string $candidateField,
        array $configuration,
        ?bool $premiumEnabled,
    ): bool {
        $premiumDetailFields = [
            'first_coupon_pre_integralization_business_days',
            'first_coupon_pre_integralization_apply_index_factor',
            'first_coupon_pre_integralization_apply_spread_factor',
        ];

        if (in_array($candidateField, $premiumDetailFields, true)) {
            return $premiumEnabled === false;
        }

        return $configuration[$candidateField] === null;
    }

    /** @return array<string, mixed> */
    private function curveStartField(
        mixed $value,
        ?EmissionPuBaselineEvidence $evidence,
    ): array {
        return [
            'field' => 'curve_start_date',
            'label' => 'Início da curva',
            'value' => $value,
            'document' => $evidence?->document?->title ?? 'Liquidação pendente',
            'document_id' => $evidence?->document_id,
            'reference' => $evidence?->reference,
            'clause' => null,
            'page' => null,
            'excerpt' => $evidence?->notes,
            'validation_status' => $evidence?->status->value,
            'reviewer' => $evidence?->reviewedBy?->name,
            'reviewed_at' => $evidence?->reviewed_at?->toIso8601String(),
            'status' => $evidence === null ? 'blocked' : 'proven',
            'confidence' => $evidence?->confidence ?? 'pending',
            'ready_for_future_persistence' => $evidence !== null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function evidence(?LegalInstrumentField $field): ?array
    {
        if (! $field instanceof LegalInstrumentField) {
            return null;
        }

        return [
            'document_id' => $field->document?->id ?? $field->instrumentDocument?->document?->id,
            'document' => $field->document_label,
            'reference' => $field->source_label,
            'clause' => $field->clause,
            'page' => $field->page,
            'excerpt' => $field->excerpt,
            'validation_status' => $field->status->value,
            'evidence_level' => $field->evidence_level->value,
            'confidence_score' => $field->confidence_score,
            'confidence' => $this->confidence($field),
            'reviewer' => $field->reviewer?->name,
            'reviewed_at' => $field->reviewed_at?->toIso8601String(),
        ];
    }

    private function confidence(?LegalInstrumentField $field): string
    {
        if (! $field instanceof LegalInstrumentField || $field->confidence_score === null) {
            return 'pending';
        }

        return match (true) {
            $field->confidence_score >= 0.8 => 'high',
            $field->confidence_score >= 0.5 => 'medium',
            default => 'low',
        };
    }

    /**
     * Percentual contratual em pontos percentuais, como a engine consome.
     *
     * `LegalInstrumentFieldValueType::Percentage` guarda FRAÇÃO: `format()`
     * multiplica por 100 para exibir e `normalizeNumeric()` divide por 100
     * qualquer leitura acima de 10, de modo que 7,5% é gravado como `0.075` e
     * 100% como `1.0`. Já `emission_pu_parameters.spread_rate` é consumido em
     * pontos percentuais — `DailyFactorCalculator` divide por 100 antes de
     * compor o fator. A multiplicação por 100 aqui é a conversão entre as duas
     * unidades, não uma correção de escala: 0.075 → `7.50000000`.
     *
     * Um valor acima de 10 (mais de 1000%) é rejeitado por `isUsableFraction()`
     * antes de chegar aqui, porque nesse ponto a unidade da linha é ambígua e
     * converter escolheria uma interpretação em vez de exigir a evidência.
     *
     * @param  array<string, mixed>  $field
     */
    private function percentage(array $field): string
    {
        if (! $field['valid'] || ! is_numeric($field['value'])) {
            return self::PENDING;
        }

        return number_format((float) $field['value'] * 100, 8, '.', '');
    }

    /**
     * O valor está no domínio de fração que o módulo jurídico garante?
     *
     * `normalizeNumeric()` só divide por 100 acima de 10, então nada acima
     * desse limite deveria existir gravado. Quando existe, a linha foi escrita
     * fora do extrator e a unidade não é determinável — bloquear é mais barato
     * que transformar 6 em 600 ou 1 em 1%.
     */
    private function isUsableFraction(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0 && (float) $value <= 10.0;
    }

    /** @param array<string, mixed> $field */
    private function decimal(array $field, int $scale): string
    {
        if (! $field['valid'] || ! is_numeric($field['value'])) {
            return self::PENDING;
        }

        return number_format((float) $field['value'], $scale, '.', '');
    }

    /** @param array<string, mixed> $field */
    private function integer(array $field): int|string
    {
        return $field['valid'] && is_numeric($field['value'])
            ? (int) $field['value']
            : self::PENDING;
    }

    /** @param array<string, mixed> $field */
    private function upperText(array $field): string
    {
        return $field['valid']
            ? mb_strtoupper(trim((string) $field['value']))
            : self::PENDING;
    }

    private function textValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_strtolower(trim($value)) : null;
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return match ((int) $value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        return match (mb_strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'sim', 'enabled' => true,
            '0', 'false', 'no', 'não', 'nao', 'disabled' => false,
            default => null,
        };
    }

    private function dateValue(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->toDateString() === $value ? $date : null;
    }

    private function dateString(mixed $value): ?string
    {
        return $this->dateValue($value)?->toDateString();
    }

    private function evidencedDate(?EmissionPuBaselineEvidence $evidence): ?CarbonImmutable
    {
        return $this->dateValue($evidence?->evidenced_value);
    }

    private function displayValue(LegalInstrumentFieldKey $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($key->valueType() === LegalInstrumentFieldValueType::Percentage && is_numeric($value)) {
            return number_format((float) $value * 100, 8, '.', '');
        }

        return $value;
    }
}
