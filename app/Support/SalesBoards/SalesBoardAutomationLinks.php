<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Filament\Resources\SalesBoardAutomationTargets\SalesBoardAutomationTargetResource;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Filament\Resources\SalesBoardRollouts\SalesBoardRolloutResource;
use Closure;
use Throwable;

/**
 * Para onde um aviso da automação leva quem o recebe.
 *
 * Um aviso que só diz o nome do empreendimento e o mês obriga a pessoa a achar a
 * competência na mão. O link vai para a tela em que a ação acontece: o ciclo,
 * quando há ciclo; a automação, quando o problema é da apuração; o rollout,
 * quando o que parou foi a Emissão inteira.
 *
 * Gerado no momento do aviso, dentro do comando agendado -- sem requisição e sem
 * painel corrente. Se a URL não puder ser montada, o aviso sai sem link: perder
 * o atalho é melhor do que perder o aviso.
 */
final class SalesBoardAutomationLinks
{
    public static function automationScreen(): ?string
    {
        return self::safely(fn (): string => SalesBoardAutomationTargetResource::getUrl('index', panel: 'admin'));
    }

    public static function cycle(int|string|null $cycleId): ?string
    {
        if (blank($cycleId)) {
            return null;
        }

        return self::safely(fn (): string => SalesBoardCycleResource::getUrl('view', ['record' => $cycleId], panel: 'admin'));
    }

    public static function rollout(int|string|null $emissionId): ?string
    {
        if (blank($emissionId)) {
            return null;
        }

        return self::safely(fn (): string => SalesBoardRolloutResource::getUrl('manage', ['record' => $emissionId], panel: 'admin'));
    }

    /**
     * @param  Closure(): string  $url
     */
    private static function safely(Closure $url): ?string
    {
        try {
            return $url();
        } catch (Throwable) {
            return null;
        }
    }
}
