<?php

namespace App\Services\Obligations;

use App\Actions\Emissions\GenerateObligationOccurrencesAction;
use App\Enums\AccessPermission;
use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInvalidDayPolicy;
use App\Enums\ObligationSeriesStatus;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\Obligation;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ObligationSeriesService
{
    private const DEFINITION_FIELDS = [
        'title',
        'obligation_type',
        'obligation_category',
        'description',
        'responsible_party',
        'responsible_area',
        'responsible_user_id',
        'priority',
        'required_evidence',
        'due_rule',
        'source_clause',
        'source_page',
        'source_excerpt',
        'document_id',
    ];

    private const CONFIGURATION_FIELDS = [
        'frequency',
        'starts_on',
        'ends_on',
        'due_rule_type',
        'due_day',
        'due_offset_months',
        'due_offset_days',
        'invalid_day_policy',
        'calendar_code',
        'generation_horizon_days',
    ];

    public function __construct(
        private readonly GenerateObligationOccurrencesAction $generateOccurrences,
        private readonly ObligationScheduleCalculator $scheduleCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createConfigured(Emission $emission, User $actor, array $data): ObligationSeries
    {
        $this->authorize($actor, AccessPermission::ObligationsCreate);

        return $this->createConfiguredWithoutAuthorization($emission, $actor, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromSuggestion(
        ExtractedObligation $suggestion,
        User $actor,
        ?array $data,
    ): ObligationSeries {
        $definition = [
            'extracted_obligation_id' => $suggestion->id,
            'document_id' => $suggestion->document_id,
            'responsible_user_id' => $suggestion->responsible_user_id,
            'title' => $suggestion->title,
            'obligation_type' => $suggestion->obligation_type,
            'obligation_category' => $suggestion->obligation_category,
            'description' => $suggestion->description,
            'responsible_party' => $suggestion->responsible_party,
            'responsible_area' => $suggestion->responsible_area,
            'priority' => $suggestion->priority,
            'required_evidence' => $suggestion->required_evidence,
            'due_rule' => $suggestion->due_rule,
            'source_clause' => $suggestion->source_clause,
            'source_page' => $suggestion->source_page,
            'source_excerpt' => $suggestion->source_excerpt,
        ];

        if ($data === null) {
            $series = $suggestion->emission->obligationSeries()->create(array_merge($definition, [
                'frequency' => ObligationFrequency::fromLegacyLabel($suggestion->recurrence),
                'status' => ObligationSeriesStatus::AwaitingConfiguration,
                'is_legacy_backfill' => false,
            ]));

            $this->recordEvent(
                $series,
                $actor,
                'series_awaiting_configuration',
                'Série criada e mantida sem geração até a confirmação humana da regra executável.',
            );

            return $series;
        }

        return $this->createConfiguredWithoutAuthorization(
            $suggestion->emission,
            $actor,
            array_merge($definition, $data, [
                'extracted_obligation_id' => $suggestion->id,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function configure(ObligationSeries $series, User $actor, array $data): ObligationSeries
    {
        $this->authorize($actor, AccessPermission::ObligationsUpdate);
        $configuration = $this->validatedConfiguration(array_merge($series->only(self::DEFINITION_FIELDS), $data));

        $configuredSeries = DB::transaction(function () use ($series, $actor, $configuration): ObligationSeries {
            $lockedSeries = ObligationSeries::query()->lockForUpdate()->findOrFail($series->getKey());

            if ($lockedSeries->status !== ObligationSeriesStatus::AwaitingConfiguration) {
                throw ValidationException::withMessages([
                    'series' => 'A configuração inicial só pode ser confirmada para uma série que esteja aguardando configuração.',
                ]);
            }

            $lockedSeries->update(array_merge(
                Arr::only($configuration, [...self::DEFINITION_FIELDS, ...self::CONFIGURATION_FIELDS]),
                [
                    'status' => ObligationSeriesStatus::Active,
                    'configuration_confirmed_at' => now(),
                    'configuration_confirmed_by' => $actor->id,
                    'paused_at' => null,
                    'paused_by' => null,
                    'pause_reason' => null,
                    'closed_at' => null,
                    'closed_by' => null,
                    'close_reason' => null,
                ],
            ));

            $rule = $this->createRule($lockedSeries, $actor, $configuration, 1, $lockedSeries->starts_on, 'Configuração inicial confirmada.');
            $this->configureLegacyOccurrence($lockedSeries, $rule);

            $this->recordEvent(
                $lockedSeries,
                $actor,
                'series_configured',
                'Regra executável confirmada e série ativada.',
                ['rule_version' => $rule->version],
            );

            return $lockedSeries->refresh();
        });

        $this->generateOccurrences->generateForSeries($configuredSeries);

        return $configuredSeries->refresh();
    }

    public function pause(ObligationSeries $series, User $actor, ?string $reason): ObligationSeries
    {
        $this->authorize($actor, AccessPermission::ObligationsUpdate);
        $reason = $this->requiredReason($reason, 'pause_reason', 'Informe o motivo da pausa.');

        if ($series->status !== ObligationSeriesStatus::Active) {
            throw ValidationException::withMessages(['series' => 'Somente uma série ativa pode ser pausada.']);
        }

        $series->update([
            'status' => ObligationSeriesStatus::Paused,
            'paused_at' => now(),
            'paused_by' => $actor->id,
            'pause_reason' => $reason,
        ]);

        $this->recordEvent($series, $actor, 'series_paused', 'Recorrência pausada. Motivo: '.$reason);

        return $series->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDefinition(ObligationSeries $series, User $actor, array $data): ObligationSeries
    {
        $this->authorize($actor, AccessPermission::ObligationsUpdate);

        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'obligation_type' => ['nullable', 'string', 'max:255'],
            'obligation_category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'responsible_party' => ['nullable', 'string', 'max:255'],
            'responsible_area' => ['nullable', 'string', 'max:255'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', Rule::in(array_keys(Obligation::PRIORITY_OPTIONS))],
            'required_evidence' => ['nullable', 'string'],
            'due_rule' => ['nullable', 'string'],
            'source_clause' => ['nullable', 'string'],
            'source_page' => ['nullable', 'integer', 'min:1'],
            'source_excerpt' => ['nullable', 'string'],
        ])->validate();

        $series->update($validated);
        $this->recordEvent(
            $series,
            $actor,
            'series_definition_updated',
            'Definição da série atualizada. Ocorrências anteriores permaneceram inalteradas.',
        );

        return $series->refresh();
    }

    public function reactivate(ObligationSeries $series, User $actor, ?string $reason): ObligationSeries
    {
        $this->authorize($actor, AccessPermission::ObligationsUpdate);
        $reason = $this->requiredReason($reason, 'reactivation_reason', 'Informe o motivo da reativação.');

        if ($series->status !== ObligationSeriesStatus::Paused) {
            throw ValidationException::withMessages(['series' => 'Somente uma série pausada pode ser reativada.']);
        }

        $series->update([
            'status' => ObligationSeriesStatus::Active,
            'paused_at' => null,
            'paused_by' => null,
            'pause_reason' => null,
        ]);

        $this->recordEvent($series, $actor, 'series_reactivated', 'Recorrência reativada. Motivo: '.$reason);
        $this->generateOccurrences->generateForSeries($series);

        return $series->refresh();
    }

    public function close(ObligationSeries $series, User $actor, ?string $reason): ObligationSeries
    {
        $this->authorize($actor, AccessPermission::ObligationsUpdate);
        $reason = $this->requiredReason($reason, 'close_reason', 'Informe o motivo do encerramento da recorrência.');

        if ($series->status === ObligationSeriesStatus::Closed) {
            throw ValidationException::withMessages(['series' => 'Esta recorrência já está encerrada.']);
        }

        $series->update([
            'status' => ObligationSeriesStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'close_reason' => $reason,
        ]);

        $this->recordEvent($series, $actor, 'series_closed', 'Recorrência encerrada. Motivo: '.$reason);

        return $series->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOnDemandOccurrence(ObligationSeries $series, User $actor, array $data): Obligation
    {
        $this->authorize($actor, AccessPermission::ObligationsCreate);

        if ($series->status !== ObligationSeriesStatus::Active || $series->frequency !== ObligationFrequency::OnDemand) {
            throw ValidationException::withMessages([
                'series' => 'A geração manual só está disponível para uma recorrência sob demanda ativa.',
            ]);
        }

        $validated = Validator::make($data, [
            'competence_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();
        $competenceDate = CarbonImmutable::parse($validated['competence_date'])->startOfMonth();

        if ($competenceDate->lt($series->starts_on->startOfMonth()) || $competenceDate->gt($series->ends_on->endOfMonth())) {
            throw ValidationException::withMessages([
                'competence_date' => 'A competência deve estar dentro da vigência da recorrência.',
            ]);
        }

        $rule = $series->rules()->whereDate('effective_from', '<=', $competenceDate)->latest('effective_from')->firstOrFail();

        try {
            $obligation = Obligation::create($this->occurrenceSnapshot($series, $rule, [
                'competence_date' => $competenceDate->toDateString(),
                'due_date' => $validated['due_date'],
                'responsible_user_id' => $validated['responsible_user_id'] ?? $series->responsible_user_id,
                'generation_source' => Obligation::GENERATION_SOURCE_ON_DEMAND,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'competence_date' => 'Já existe uma ocorrência para esta série e competência.',
            ]);
        }

        $this->recordEvent(
            $series,
            $actor,
            'on_demand_occurrence_created',
            'Ocorrência sob demanda criada para '.$obligation->competence_label.'.',
            ['obligation_id' => $obligation->id],
        );

        return $obligation;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reviseRuleFrom(ObligationSeries $series, User $actor, array $data): ObligationSeries
    {
        $this->authorize($actor, AccessPermission::ObligationsUpdate);

        if (in_array($series->status, [
            ObligationSeriesStatus::AwaitingConfiguration,
            ObligationSeriesStatus::Closed,
        ], true)) {
            throw ValidationException::withMessages([
                'series' => 'A regra só pode ser revisada para uma série ativa ou pausada.',
            ]);
        }

        Validator::make($data, [
            'effective_from' => ['required', 'date'],
        ])->validate();

        $revisionData = array_merge(
            $series->only([...self::DEFINITION_FIELDS, ...self::CONFIGURATION_FIELDS]),
            $data,
        );
        $revisionData['starts_on'] = $series->starts_on;
        $configuration = $this->validatedConfiguration($revisionData);
        $effectiveFrom = CarbonImmutable::parse($data['effective_from'])->startOfMonth();
        $reason = $this->requiredReason($data['change_reason'] ?? null, 'change_reason', 'Informe o motivo da alteração da regra.');

        if ($effectiveFrom->lt($series->starts_on->startOfMonth()) || $effectiveFrom->gt($series->ends_on->endOfMonth())) {
            throw ValidationException::withMessages([
                'effective_from' => 'A competência inicial da nova regra deve estar dentro da vigência da série.',
            ]);
        }

        $result = DB::transaction(function () use ($series, $actor, $configuration, $effectiveFrom, $reason): array {
            $lockedSeries = ObligationSeries::query()->lockForUpdate()->findOrFail($series->getKey());

            if ($lockedSeries->rules()->whereDate('effective_from', $effectiveFrom)->exists()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Já existe uma versão de regra iniciando nesta competência.',
                ]);
            }

            $version = ((int) $lockedSeries->rules()->max('version')) + 1;
            $rule = $this->createRule($lockedSeries, $actor, $configuration, $version, $effectiveFrom, $reason);
            $lockedSeries->update(Arr::only($configuration, self::CONFIGURATION_FIELDS));

            $recalculableOccurrences = $lockedSeries->occurrences()
                ->whereDate('competence_date', '>=', $effectiveFrom)
                ->where('generation_source', Obligation::GENERATION_SOURCE_AUTOMATIC)
                ->whereIn('status', ['em_dia', 'a_vencer', 'vencida'])
                ->whereDoesntHave('evidences')
                ->whereDoesntHave('comments')
                ->whereDoesntHave('notifications')
                ->get();
            $recalculated = $recalculableOccurrences->count();
            $recalculableOccurrences->each->delete();

            $protected = $lockedSeries->occurrences()
                ->whereDate('competence_date', '>=', $effectiveFrom)
                ->count();

            $this->recordEvent(
                $lockedSeries,
                $actor,
                'series_rule_revised',
                sprintf('Regra alterada a partir da competência %s. Motivo: %s', $effectiveFrom->format('m/Y'), $reason),
                [
                    'rule_version' => $rule->version,
                    'effective_from' => $effectiveFrom->toDateString(),
                    'recalculated_occurrences' => $recalculated,
                    'protected_occurrences' => $protected,
                ],
            );

            return ['series' => $lockedSeries->refresh(), 'protected' => $protected];
        });

        $this->generateOccurrences->generateForSeries($result['series']);

        return $result['series']->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createConfiguredWithoutAuthorization(Emission $emission, User $actor, array $data): ObligationSeries
    {
        $configuration = $this->validatedConfiguration($data);

        $series = DB::transaction(function () use ($emission, $actor, $configuration, $data): ObligationSeries {
            $series = $emission->obligationSeries()->create(array_merge(
                Arr::only($configuration, [...self::DEFINITION_FIELDS, ...self::CONFIGURATION_FIELDS]),
                Arr::only($data, ['extracted_obligation_id']),
                [
                    'status' => ObligationSeriesStatus::Active,
                    'configuration_confirmed_at' => now(),
                    'configuration_confirmed_by' => $actor->id,
                ],
            ));

            $rule = $this->createRule($series, $actor, $configuration, 1, $series->starts_on, 'Configuração inicial confirmada.');
            $this->recordEvent(
                $series,
                $actor,
                'series_created',
                'Série recorrente criada com regra executável confirmada.',
                ['rule_version' => $rule->version],
            );

            return $series;
        });

        $this->generateOccurrences->generateForSeries($series);

        return $series->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatedConfiguration(array $data): array
    {
        foreach (['frequency', 'due_rule_type', 'invalid_day_policy'] as $field) {
            if (($data[$field] ?? null) instanceof \BackedEnum) {
                $data[$field] = $data[$field]->value;
            }
        }

        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'obligation_type' => ['nullable', 'string', 'max:255'],
            'obligation_category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'responsible_party' => ['nullable', 'string', 'max:255'],
            'responsible_area' => ['nullable', 'string', 'max:255'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', Rule::in(array_keys(Obligation::PRIORITY_OPTIONS))],
            'required_evidence' => ['nullable', 'string'],
            'due_rule' => ['nullable', 'string'],
            'source_clause' => ['nullable', 'string'],
            'source_page' => ['nullable', 'integer', 'min:1'],
            'source_excerpt' => ['nullable', 'string'],
            'document_id' => ['nullable', 'integer', 'exists:documents,id'],
            'frequency' => ['required', Rule::in(array_keys(ObligationFrequency::seriesOptions()))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'due_rule_type' => ['nullable', Rule::enum(ObligationDueRuleType::class), 'required_unless:frequency,on_demand'],
            'due_day' => ['nullable', 'integer', 'between:1,31', 'required_if:due_rule_type,fixed_day,nth_business_day'],
            'due_offset_months' => [
                'nullable',
                'integer',
                'between:-12,12',
                Rule::requiredIf(fn (): bool => ($data['frequency'] ?? null) !== ObligationFrequency::OnDemand->value
                    && ($data['due_rule_type'] ?? null) !== ObligationDueRuleType::CalendarDaysAfterCompetenceEnd->value),
            ],
            'due_offset_days' => [
                'nullable',
                'integer',
                'between:1,3650',
                'required_if:due_rule_type,calendar_days_after_competence_end',
            ],
            'invalid_day_policy' => ['nullable', Rule::enum(ObligationInvalidDayPolicy::class), 'required_if:due_rule_type,fixed_day'],
            'calendar_code' => ['nullable', 'string', Rule::in(array_keys((array) config('obligations.recurrence.calendar_options', ['B3' => 'B3']))), 'required_if:due_rule_type,nth_business_day'],
            'generation_horizon_days' => ['required', 'integer', 'between:30,730'],
        ], [
            'ends_on.required' => 'Informe o término da recorrência.',
            'ends_on.after_or_equal' => 'O término deve ser igual ou posterior à competência inicial.',
            'due_rule_type.required_unless' => 'Confirme a regra executável de vencimento.',
            'due_day.required_if' => 'Informe o dia utilizado pela regra executável.',
            'due_offset_days.required_if' => 'Informe quantos dias corridos devem ser contados após o encerramento da competência.',
            'invalid_day_policy.required_if' => 'Defina o comportamento quando o dia não existir no mês.',
            'calendar_code.required_if' => 'Selecione o calendário de dias úteis.',
        ])->validate();

        $startsOn = CarbonImmutable::parse($validated['starts_on'])->startOfMonth();
        $endsOn = CarbonImmutable::parse($validated['ends_on'])->endOfDay();

        if ($startsOn->diffInYears($endsOn) > 50) {
            throw ValidationException::withMessages([
                'ends_on' => 'A vigência máxima permitida para uma recorrência é de 50 anos.',
            ]);
        }

        $validated['starts_on'] = $startsOn->toDateString();
        $validated['ends_on'] = $endsOn->toDateString();
        $validated['due_offset_months'] ??= 0;
        $validated['due_offset_days'] ??= null;

        if ($validated['frequency'] === ObligationFrequency::OnDemand->value) {
            $validated['due_rule_type'] = null;
        }

        $dueRuleType = $validated['due_rule_type'] ?? null;

        if ($dueRuleType === ObligationDueRuleType::CalendarDaysAfterCompetenceEnd->value) {
            $validated['due_offset_months'] = 0;
        } else {
            $validated['due_offset_days'] = null;
        }

        if (! in_array($dueRuleType, [ObligationDueRuleType::FixedDay->value, ObligationDueRuleType::NthBusinessDay->value], true)) {
            $validated['due_day'] = null;
        }

        if ($dueRuleType !== ObligationDueRuleType::FixedDay->value) {
            $validated['invalid_day_policy'] = null;
        }

        if ($dueRuleType !== ObligationDueRuleType::NthBusinessDay->value) {
            $validated['calendar_code'] = null;
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function createRule(
        ObligationSeries $series,
        User $actor,
        array $configuration,
        int $version,
        \DateTimeInterface|string $effectiveFrom,
        string $reason,
    ): ObligationSeriesRule {
        return $series->rules()->create([
            'version' => $version,
            'effective_from' => CarbonImmutable::parse($effectiveFrom)->startOfMonth(),
            'frequency' => $configuration['frequency'],
            'due_rule_type' => $configuration['due_rule_type'] ?? null,
            'due_day' => $configuration['due_day'] ?? null,
            'due_offset_months' => $configuration['due_offset_months'],
            'due_offset_days' => $configuration['due_offset_days'] ?? null,
            'invalid_day_policy' => $configuration['invalid_day_policy'] ?? null,
            'calendar_code' => $configuration['calendar_code'] ?? null,
            'created_by' => $actor->id,
            'change_reason' => $reason,
        ]);
    }

    private function configureLegacyOccurrence(ObligationSeries $series, ObligationSeriesRule $rule): void
    {
        $legacyOccurrence = $series->occurrences()
            ->whereNull('competence_date')
            ->oldest('id')
            ->first();

        if ($legacyOccurrence === null) {
            return;
        }

        $dueDate = $legacyOccurrence->due_date
            ?? $this->scheduleCalculator->resolveDueDate($rule, $series->starts_on);

        $legacyOccurrence->forceFill([
            'obligation_series_rule_id' => $rule->id,
            'competence_date' => $series->starts_on,
            'generation_source' => Obligation::GENERATION_SOURCE_LEGACY,
            'recurrence' => $rule->frequency->label(),
            'due_date' => $dueDate,
            'status' => $dueDate === null
                ? $legacyOccurrence->status
                : ($dueDate->copy()->startOfDay()->lt(now()->startOfDay()) ? 'vencida' : 'a_vencer'),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function occurrenceSnapshot(
        ObligationSeries $series,
        ObligationSeriesRule $rule,
        array $overrides,
    ): array {
        $dueDate = CarbonImmutable::parse($overrides['due_date']);

        return array_merge([
            'emission_id' => $series->emission_id,
            'obligation_series_id' => $series->id,
            'obligation_series_rule_id' => $rule->id,
            'generated_at' => now(),
            'extracted_obligation_id' => $series->occurrences()->whereNotNull('extracted_obligation_id')->exists()
                ? null
                : $series->extracted_obligation_id,
            'responsible_user_id' => $series->responsible_user_id,
            'title' => $series->title,
            'obligation_type' => $series->obligation_type,
            'obligation_category' => $series->obligation_category,
            'description' => $series->description,
            'responsible_party' => $series->responsible_party,
            'responsible_area' => $series->responsible_area,
            'recurrence' => $rule->frequency->label(),
            'due_rule' => $series->due_rule,
            'priority' => $series->priority,
            'status' => $dueDate->lt(now()->startOfDay()) ? 'vencida' : 'a_vencer',
            'required_evidence' => $series->required_evidence,
            'source_clause' => $series->source_clause,
            'source_page' => $series->source_page,
            'source_excerpt' => $series->source_excerpt,
        ], $overrides);
    }

    private function requiredReason(?string $reason, string $field, string $message): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $reason;
    }

    private function authorize(User $actor, AccessPermission $permission): void
    {
        if ($actor->can($permission->value)) {
            return;
        }

        throw new AuthorizationException('Você não tem permissão para gerenciar recorrências de obrigações.');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordEvent(
        ObligationSeries $series,
        User $actor,
        string $event,
        string $description,
        array $properties = [],
    ): void {
        activity('obligation_series')
            ->performedOn($series)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(array_merge([
                'emission_id' => $series->emission_id,
                'series_id' => $series->id,
            ], $properties))
            ->log($description);
    }
}
