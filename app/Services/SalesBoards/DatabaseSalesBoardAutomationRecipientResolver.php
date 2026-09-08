<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAlertType;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\User;

/**
 * Os destinatários reais da automação, vindos da configuração de rollout.
 *
 * Substitui o resolvedor diferido da Fase F. Aquele devolvia lista vazia porque
 * não existia fonte confiável -- `Emission` e `Construction` não têm
 * responsável, e o de `Operation` responde pelo fluxo de medição. A Fase G criou
 * a fonte que faltava, e ela é explícita: alguém escolhe, por Emissão e por
 * papel.
 *
 * Continua proibido o atalho: nada de "todos os administradores" nem "todos com
 * `sales-boards.update`". Lista vazia permanece um resultado legítimo -- o motor
 * de alertas registra o aviso estruturado e a execução segue.
 */
class DatabaseSalesBoardAutomationRecipientResolver implements SalesBoardAutomationRecipientResolver
{
    public function __construct(
        private readonly SalesBoardRolloutRecipientDirectory $directory,
    ) {}

    public function forGenerationBlocked(SalesBoardAutomationTarget $target): array
    {
        return $this->forConstruction(
            $target->construction_id,
            SalesBoardRolloutRecipientRole::forAlert(SalesBoardAutomationAlertType::GenerationBlocked),
        );
    }

    public function forGenerationFailed(SalesBoardAutomationTarget $target): array
    {
        return $this->forConstruction(
            $target->construction_id,
            SalesBoardRolloutRecipientRole::forAlert(SalesBoardAutomationAlertType::GenerationFailed),
        );
    }

    public function forBuilderHandoff(SalesBoardCycle $cycle): array
    {
        return $this->forEmissionId(
            $cycle->emission_id,
            SalesBoardRolloutRecipientRole::forAlert(SalesBoardAutomationAlertType::ReadyForBuilder),
        );
    }

    public function forBuilderReminder(SalesBoardBuilderReview $review): array
    {
        return $this->forEmissionId(
            $review->cycle?->emission_id,
            SalesBoardRolloutRecipientRole::forAlert(SalesBoardAutomationAlertType::BuilderReminder),
        );
    }

    public function forManagementReminder(SalesBoardCycle $cycle): array
    {
        return $this->forEmissionId(
            $cycle->emission_id,
            SalesBoardRolloutRecipientRole::forAlert(SalesBoardAutomationAlertType::ManagementReminder),
        );
    }

    /**
     * @return list<User>
     */
    private function forConstruction(mixed $constructionId, SalesBoardRolloutRecipientRole $role): array
    {
        if (blank($constructionId)) {
            return [];
        }

        return $this->forEmissionId(
            Construction::query()->whereKey($constructionId)->value('emission_id'),
            $role,
        );
    }

    /**
     * @return list<User>
     */
    private function forEmissionId(mixed $emissionId, SalesBoardRolloutRecipientRole $role): array
    {
        if (blank($emissionId)) {
            return [];
        }

        $emission = Emission::query()->find($emissionId);

        return $emission instanceof Emission
            ? $this->directory->activeFor($emission, $role)
            : [];
    }
}
