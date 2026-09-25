<?php

namespace App\Services;

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
                'created_by' => $actor->id, 'version' => ($parent?->version ?? 0) + 1, 'supersedes_id' => $parent?->id,
            ]);
            activity('measurement_financial_rules')->performedOn($rule)->causedBy($actor)
                ->withProperties($rule->snapshot())->log('financial_rule_registered');

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
            activity('measurement_financial_rules')->performedOn($locked)->causedBy($actor)->log('financial_rule_retired');
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

    private function authorize(User $actor): void
    {
        if (! $actor->can(AccessPermission::MeasurementsFinancialRulesManage->value)) {
            throw new AuthorizationException;
        }
    }
}
