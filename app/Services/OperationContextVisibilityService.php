<?php

namespace App\Services;

use App\Models\Construction;
use App\Models\Emission;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class OperationContextVisibilityService
{
    /**
     * @return Builder<Emission>
     */
    public function visibleEmissions(User $user): Builder
    {
        $query = Emission::query();

        if ($this->hasGlobalVisibility($user)) {
            return $query;
        }

        return $query->whereHas(
            'operations',
            fn (Builder $operations): Builder => $operations->visibleTo($user),
        );
    }

    /**
     * @return Builder<Construction>
     */
    public function visibleConstructions(User $user, mixed $emissionId = null): Builder
    {
        $query = Construction::query();

        if (filled($emissionId)) {
            $query->where('emission_id', $emissionId);
        }

        if ($this->hasGlobalVisibility($user)) {
            return $query;
        }

        return $query->whereHas(
            'measurementPlanSets.operation',
            fn (Builder $operations): Builder => $operations->visibleTo($user),
        );
    }

    public function findVisibleEmission(User $user, mixed $emissionId): ?Emission
    {
        if (blank($emissionId)) {
            return null;
        }

        return $this->visibleEmissions($user)->whereKey($emissionId)->first();
    }

    public function findVisibleConstruction(User $user, mixed $constructionId, mixed $emissionId = null): ?Construction
    {
        if (blank($constructionId)) {
            return null;
        }

        return $this->visibleConstructions($user, $emissionId)->whereKey($constructionId)->first();
    }

    /**
     * @param  array<int, array{construction_id?: mixed}>  $developments
     */
    public function assertOperationPayloadIsVisible(User $user, mixed $emissionId, array $developments): Emission
    {
        $emission = $this->findVisibleEmission($user, $emissionId);

        if (! $emission instanceof Emission) {
            throw ValidationException::withMessages([
                'emission_id' => 'A emissão selecionada não está disponível.',
            ]);
        }

        foreach ($developments as $index => $development) {
            if (! $this->findVisibleConstruction($user, $development['construction_id'] ?? null, $emission->getKey()) instanceof Construction) {
                throw ValidationException::withMessages([
                    "developments.{$index}.construction_id" => 'O empreendimento selecionado não está disponível.',
                ]);
            }
        }

        return $emission;
    }

    public function assertConstructionIsVisibleForOperation(User $user, Operation $operation, mixed $constructionId): Construction
    {
        $construction = $this->findVisibleConstruction($user, $constructionId, $operation->emission_id);

        if (! $construction instanceof Construction) {
            throw ValidationException::withMessages([
                'construction_id' => 'O empreendimento selecionado não está disponível.',
            ]);
        }

        return $construction;
    }

    private function hasGlobalVisibility(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin']);
    }
}
