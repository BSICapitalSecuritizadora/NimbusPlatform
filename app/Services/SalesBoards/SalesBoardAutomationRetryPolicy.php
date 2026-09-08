<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Quando o alvo pode tentar de novo.
 *
 * Duas cadências, porque são dois problemas. Uma fonte incompleta é resolvida
 * por uma pessoa ao longo do expediente, e insistir de hora em hora produziria
 * vinte e quatro derivações completas do mesmo empreendimento sem nenhuma
 * informação nova -- caro e inútil na mesma proporção. Uma falha técnica
 * costuma ser transitória, e merece voltar cedo e ir cedendo.
 *
 * O backoff é por número de tentativa, não exponencial calculado: quatro
 * degraus explícitos são mais fáceis de conferir e de mudar do que uma fórmula,
 * e a última posição se repete até o teto.
 */
class SalesBoardAutomationRetryPolicy
{
    /**
     * A próxima tentativa depois de a fonte bloquear a geração.
     */
    public function afterBlocked(CarbonImmutable $now): CarbonImmutable
    {
        $hours = max(1, (int) Config::get('sales_board.automation.retry.blocked_after_hours', 24));

        return $now->addHours($hours);
    }

    /**
     * A próxima tentativa depois de uma falha técnica.
     */
    public function afterFailure(CarbonImmutable $now, int $attemptNumber): CarbonImmutable
    {
        $ladder = Config::get('sales_board.automation.retry.failed_backoff_hours', [1, 2, 4, 8]);
        $ladder = array_values(array_filter(
            array_map('intval', is_array($ladder) ? $ladder : []),
            fn (int $hours): bool => $hours > 0,
        ));

        if ($ladder === []) {
            $ladder = [1];
        }

        $index = min(max($attemptNumber, 1), count($ladder)) - 1;
        $hours = $ladder[$index];

        $ceiling = max(1, (int) Config::get('sales_board.automation.retry.failed_max_backoff_hours', 24));

        return $now->addHours(min($hours, $ceiling));
    }
}
