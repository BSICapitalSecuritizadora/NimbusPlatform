<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementFinancialRuleCoverage;
use App\Enums\AccessPermission;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeasurementFinancialRuleService
{
    /** @param array<string, mixed> $data */
    public function register(array $data, User $actor, ?MeasurementFinancialRule $previous = null): MeasurementFinancialRule
    {
        $this->authorize($actor);
        $data = Validator::make($data, [
            'emission_id' => ['required', 'integer', 'exists:emissions,id'],
            'construction_id' => ['nullable', 'integer', Rule::exists('constructions', 'id')->where('emission_id', $data['emission_id'] ?? null)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'direction' => ['required', Rule::in(array_keys(MeasurementFinancialRule::DIRECTIONS))],
            'maximum_difference_amount' => ['nullable', 'required_without:maximum_difference_percent', 'numeric', 'gte:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'maximum_difference_percent' => ['nullable', 'required_without:maximum_difference_amount', 'numeric', 'gte:0', 'max:999999.9999', 'decimal:0,4'],
            'requires_document' => ['required', 'boolean'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ])->validate();

        return DB::transaction(function () use ($data, $actor, $previous): MeasurementFinancialRule {
            $parent = $previous ? MeasurementFinancialRule::query()->whereKey($previous->id)->lockForUpdate()->firstOrFail() : null;
            if ($parent && ($parent->retired_at !== null || (int) $parent->emission_id !== (int) $data['emission_id'])) {
                throw ValidationException::withMessages(['emission_id' => 'A regra foi encerrada ou pertence a outra emissão. Atualize a página.']);
            }
            $parent?->update(['retired_at' => now()]);
            $rule = MeasurementFinancialRule::query()->create($data + [
                // O limite percentual da regra cadastrada a partir da Fase 4B-1
                // é sobre o valor aprovado do empreendimento na competência.
                'percent_basis' => filled($data['maximum_difference_percent'] ?? null) ? MeasurementFinancialRuleCoverage::PERCENT_BASIS_APPROVED_AMOUNT : null,
                'created_by' => $actor->id, 'version' => ($parent?->version ?? 0) + 1, 'supersedes_id' => $parent?->id,
            ]);
            activity('measurement_financial_rules')->performedOn($rule)->causedBy($actor)
                ->withProperties($rule->snapshot() + ['supersedes_id' => $parent?->id])->log('financial_rule_registered');

            return $rule;
        });
    }

    public function retire(MeasurementFinancialRule $rule, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($rule, $actor): void {
            $locked = MeasurementFinancialRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            if ($locked->retired_at !== null) {
                throw ValidationException::withMessages(['rule' => 'Esta regra já foi encerrada.']);
            }
            $locked->update(['retired_at' => now()]);
            activity('measurement_financial_rules')->performedOn($locked)->causedBy($actor)
                ->withProperties(['id' => $locked->id, 'name' => $locked->name, 'version' => $locked->version, 'created_by' => (int) $locked->created_by])
                ->log('financial_rule_retired');
        });
    }

    /** @return Builder<MeasurementFinancialRule> */
    public function availableFor(Measurement $measurement, int $planSetId, string $paymentDate): Builder
    {
        $snapshot = $measurement->engineering_snapshot ?? [];
        $plan = collect($snapshot['plan_sets'] ?? [])->firstWhere('plan_set_id', $planSetId);

        return MeasurementFinancialRule::query()
            ->when(! $plan || empty($snapshot['emission_id']), fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->where('emission_id', $snapshot['emission_id'] ?? 0)
            ->where(fn (Builder $query): Builder => $query->whereNull('construction_id')
                ->when(! empty($plan['construction_id']), fn (Builder $query): Builder => $query->orWhere('construction_id', $plan['construction_id'])))
            ->whereNull('retired_at')
            ->whereDate('effective_from', '<=', $paymentDate)
            ->where(fn (Builder $query): Builder => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $paymentDate));
    }

    /**
     * Quem registrou esta versão da regra e as versões que ela substitui: a
     * cadeia inteira. Uma versão idêntica registrada por outra pessoa não
     * apaga quem ampliou o limite antes dela.
     *
     * @return list<int>
     */
    public function authorsOf(int $ruleId): array
    {
        return self::authorsIn($this->chainOf($ruleId));
    }

    /**
     * @param  list<array{id: int, version: int, created_by: int|null}>  $chain
     * @return list<int>
     */
    public static function authorsIn(array $chain): array
    {
        return array_values(array_unique(array_filter(
            array_column($chain, 'created_by'),
            fn (?int $userId): bool => $userId !== null,
        )));
    }

    /**
     * A versão e as que ela substitui, da aplicada para a mais antiga, com
     * quem registrou cada uma.
     *
     * @return list<array{id: int, version: int, created_by: int|null}>
     */
    public function chainOf(int $ruleId): array
    {
        return $this->chainsOf([$ruleId])[$ruleId] ?? [];
    }

    /**
     * As cadeias de várias regras, numa leitura por geração de versões -- e
     * não uma por versão de cada regra.
     *
     * @param  list<int>  $ruleIds
     * @return array<int, list<array{id: int, version: int, created_by: int|null}>>
     */
    public function chainsOf(array $ruleIds): array
    {
        $rules = [];
        $pending = array_values(array_unique(array_map('intval', $ruleIds)));

        while ($pending !== []) {
            $next = [];

            foreach (MeasurementFinancialRule::query()->whereKey($pending)->get(['id', 'version', 'created_by', 'supersedes_id']) as $rule) {
                $rules[(int) $rule->id] = $rule;

                if ($rule->supersedes_id !== null) {
                    $next[] = (int) $rule->supersedes_id;
                }
            }

            $pending = array_values(array_diff(array_unique($next), array_keys($rules)));
        }

        $chains = [];

        foreach ($ruleIds as $ruleId) {
            $chain = [];
            $currentId = (int) $ruleId;

            while (isset($rules[$currentId]) && ! in_array($currentId, array_column($chain, 'id'), true)) {
                $rule = $rules[$currentId];
                $chain[] = [
                    'id' => (int) $rule->id,
                    'version' => (int) $rule->version,
                    'created_by' => $rule->created_by === null ? null : (int) $rule->created_by,
                ];
                $currentId = (int) $rule->supersedes_id;
            }

            $chains[(int) $ruleId] = $chain;
        }

        return $chains;
    }

    private function authorize(User $actor): void
    {
        if (! $actor->can(AccessPermission::MeasurementsFinancialRulesManage->value)) {
            throw new AuthorizationException;
        }
    }
}
