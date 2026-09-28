<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\Models\Emission;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardAutomationRecipientResolver;

/**
 * Um resolvedor que entrega sempre os mesmos destinatários, para qualquer aviso.
 *
 * Para os testes do motor de avisos: com o destinatário fixo, a presença ou a
 * ausência de um aviso só pode ser explicada pela regra sob teste -- limiar,
 * perímetro, interruptor --, e não pela configuração de rollout.
 */
final class FixedSalesBoardAutomationRecipientResolver implements SalesBoardAutomationRecipientResolver
{
    /**
     * @param  list<User>  $users
     */
    public function __construct(private readonly array $users) {}

    public static function bind(User ...$users): void
    {
        app()->instance(SalesBoardAutomationRecipientResolver::class, new self(array_values($users)));
    }

    public function forGenerationBlocked(SalesBoardAutomationTarget $target): array
    {
        return $this->users;
    }

    public function forGenerationFailed(SalesBoardAutomationTarget $target): array
    {
        return $this->users;
    }

    public function forBuilderHandoff(SalesBoardCycle $cycle): array
    {
        return $this->users;
    }

    public function forBuilderReminder(SalesBoardBuilderReview $review): array
    {
        return $this->users;
    }

    public function forBuilderEscalation(SalesBoardBuilderReview $review): array
    {
        return $this->users;
    }

    public function forManagementReminder(SalesBoardCycle $cycle): array
    {
        return $this->users;
    }

    public function forScopeSuspended(Emission $emission): array
    {
        return $this->users;
    }

    public function forRunInterrupted(SalesBoardAutomationRun $run): array
    {
        return $this->users;
    }
}
