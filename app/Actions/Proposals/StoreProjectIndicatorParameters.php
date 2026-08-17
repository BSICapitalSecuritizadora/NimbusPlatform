<?php

namespace App\Actions\Proposals;

use App\Enums\ProjectIndicatorDefinition;
use App\Models\ProjectIndicator;
use App\Models\ProposalProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

class StoreProjectIndicatorParameters
{
    /** @param array<string, mixed> $parameters */
    public function handle(ProposalProject $project, array $parameters): ProjectIndicator
    {
        $payload = [];
        $rules = [];

        foreach (ProjectIndicatorDefinition::cases() as $definition) {
            foreach ([$definition->idealAttribute(), $definition->limitAttribute()] as $attribute) {
                $payload[$attribute] = self::normalizeNullablePercentage($parameters[$attribute] ?? null);
                $rules[$attribute] = ['nullable', 'numeric', 'min:0', 'max:99999999.99'];
            }
        }

        $validator = ValidatorFacade::make($payload, $rules);
        $validator->after(function (Validator $validator) use ($payload): void {
            foreach (ProjectIndicatorDefinition::cases() as $definition) {
                $ideal = $payload[$definition->idealAttribute()];
                $limit = $payload[$definition->limitAttribute()];

                if (! is_numeric($ideal) || ! is_numeric($limit)) {
                    continue;
                }

                if (! $definition->direction()->thresholdsAreCoherent((float) $ideal, (float) $limit)) {
                    $validator->errors()->add(
                        $definition->limitAttribute(),
                        $definition->direction()->incoherentThresholdsMessage(),
                    );
                }
            }
        });

        $validated = $validator->validate();

        return DB::transaction(function () use ($project, $validated): ProjectIndicator {
            $indicator = $project->indicators()->updateOrCreate([], $validated);
            $project->setRelation('indicators', $indicator);

            return $indicator;
        });
    }

    public static function normalizeNullablePercentage(mixed $value): float|string|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $normalized = str_replace(['%', ' '], '', trim((string) $value));

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? round((float) $normalized, 2) : $normalized;
    }
}
