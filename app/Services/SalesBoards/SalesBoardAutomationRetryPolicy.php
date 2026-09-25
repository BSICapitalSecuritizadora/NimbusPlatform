<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Support\SalesBoards\SalesBoardAutomationConfig;
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
 * O backoff é por número de falha técnica consecutiva, não exponencial
 * calculado: quatro degraus explícitos são mais fáceis de conferir e de mudar do
 * que uma fórmula, e a última posição se repete até o teto. Bloqueio não conta
 * degrau -- um alvo bloqueado por dias não pode cair direto na espera de oito
 * horas na primeira falha técnica.
 *
 * Toda próxima tentativa é arredondada para baixo, para a hora cheia. O
 * scheduler roda no segundo zero de cada hora, com variação de milissegundos; um
 * horário gravado como 13:00:01 fazia a execução das 13:00:00 do dia seguinte
 * achar o alvo ainda em espera, e a cadência de 1 hora virava 2, a de 24 virava
 * 25 e o horário escorregava pelo dia.
 */
class SalesBoardAutomationRetryPolicy
{
    /**
     * A próxima tentativa depois de a fonte bloquear a geração.
     */
    public function afterBlocked(CarbonImmutable $now): CarbonImmutable
    {
        $hours = SalesBoardAutomationConfig::retryHours('blocked_after_hours', 24);

        return $now->addHours($hours)->startOfHour();
    }

    /**
     * A próxima tentativa depois da enésima falha técnica consecutiva.
     */
    public function afterFailure(CarbonImmutable $now, int $consecutiveFailures): CarbonImmutable
    {
        $ladder = Config::get('sales_board.automation.retry.failed_backoff_hours', [1, 2, 4, 8]);
        $ladder = array_values(array_filter(
            array_map('intval', is_array($ladder) ? $ladder : []),
            fn (int $hours): bool => $hours > 0,
        ));

        if ($ladder === []) {
            $ladder = [1];
        }

        $index = min(max($consecutiveFailures, 1), count($ladder)) - 1;
        $hours = $ladder[$index];

        $ceiling = SalesBoardAutomationConfig::retryHours('failed_max_backoff_hours', 24);

        return $now->addHours(min($hours, $ceiling))->startOfHour();
    }
}
