<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardAutomationEligibleTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A habilitação vem da configuração, enquanto a Fase G não decide o rollout.
 *
 * Nasce vazia. Um ambiente que receba as migrations desta fase sem ter escolhido
 * nada não automatiza empreendimento nenhum -- e essa é a propriedade que
 * permite aplicar o schema muito antes da decisão de operação.
 *
 * Entradas malformadas são **descartadas com aviso**, não corrigidas. Um alvo
 * sem competência de ativação seria automaticamente "desde sempre", que é
 * exatamente o backfill que a fase proíbe; um `construction_id` inválido geraria
 * consulta a um empreendimento inexistente. Nos dois casos a resposta correta é
 * ignorar a linha e dizer alto que ela foi ignorada.
 */
class ConfiguredSalesBoardAutomationEligibilityProvider implements SalesBoardAutomationEligibilityProvider
{
    public function eligibleTargets(): array
    {
        if (! Config::get('sales_board.automation.enabled', false)) {
            return [];
        }

        $configured = Config::get('sales_board.automation.targets', []);

        if (! is_array($configured) || $configured === []) {
            return [];
        }

        $targets = [];

        foreach ($configured as $index => $entry) {
            $target = $this->parse($entry, (string) $index);

            if ($target !== null) {
                $targets[$target->constructionId] = $target;
            }
        }

        return array_values($targets);
    }

    /**
     * @param  mixed  $entry
     */
    private function parse($entry, string $index): ?SalesBoardAutomationEligibleTarget
    {
        if (! is_array($entry)) {
            $this->discard($index, 'entry_not_an_array');

            return null;
        }

        $constructionId = (int) ($entry['construction_id'] ?? 0);

        if ($constructionId <= 0) {
            $this->discard($index, 'missing_construction_id');

            return null;
        }

        $startMonth = $entry['start_reference_month'] ?? null;

        if (blank($startMonth)) {
            $this->discard($index, 'missing_start_reference_month');

            return null;
        }

        try {
            $start = CarbonImmutable::parse((string) $startMonth)->startOfMonth();
        } catch (Throwable) {
            $this->discard($index, 'unparseable_start_reference_month');

            return null;
        }

        return new SalesBoardAutomationEligibleTarget(
            constructionId: $constructionId,
            startReferenceMonth: $start,
            autoOpenBuilderReview: (bool) ($entry['auto_open_builder_review'] ?? false),
        );
    }

    /**
     * O descarte é ruidoso de propósito: uma configuração de automação que não
     * faz nada em silêncio é indistinguível de uma automação desligada, e a
     * pessoa que a escreveu ficaria esperando por competências que nunca viriam.
     */
    private function discard(string $index, string $reason): void
    {
        Log::warning('Sales board automation target discarded', [
            'event' => 'sales_board_automation_target_discarded',
            'index' => $index,
            'reason' => $reason,
        ]);
    }
}
