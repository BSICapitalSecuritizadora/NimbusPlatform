<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Exceptions\PuNonOperationalProfileException;

/**
 * Portão que mantém o perfil legado fora da operação.
 *
 * O perfil de cálculo é carimbado na memória de CADA linha da curva
 * ({@see PuCurveGenerationService}), então o dado carrega a sua própria procedência: não
 * é preciso confiar em quem chamou a engine para saber sob qual metodologia a linha nasceu.
 *
 * Hoje nenhum caminho operacional informa perfil -- todos caem no default contratual --, e
 * é justamente por isso que este portão é barato: ele nunca dispara no fluxo atual. Ele existe
 * para que a garantia "legado é só simulação" seja ESTRUTURAL e não apenas uma convenção
 * entre chamadores, que um caminho futuro poderia quebrar em silêncio.
 *
 * Fica nos dois pontos em que linhas de curva viram dado persistido:
 * {@see PuCurvePersistenceService::handle()} (curva oficial) e
 * {@see PuCandidateCurveService::generate()} (candidate, homologação e promoção).
 */
final class PuOperationalProfileGuard
{
    /**
     * Recusa a escrita se QUALQUER linha tiver nascido em perfil não operacional.
     *
     * Falha FECHADO em dois casos:
     *
     *  1. carimbo presente com valor desconhecido -- um perfil que a engine não
     *     reconhece não pode ser declarado contratual;
     *  2. carimbo AUSENTE numa linha da engine contratual corrente
     *     ({@see PuAuditLogService::ENGINE_VERSION}) -- toda curva nova da `v2` carimba
     *     o perfil, então a ausência significa memória adulterada ou montada à mão,
     *     e uma curva `v2` sem procedência de perfil não entra na operação.
     *
     * Linha de OUTRA versão de motor sem carimbo é aceita como histórico: a `phase1-cdi-v1`
     * é anterior ao conceito de perfil (e, na época dela, só existia o cálculo contratual),
     * e os motores prefixado e IPCA gravam `engine_version` próprio sem carimbar perfil,
     * porque o perfil legado nunca foi provado fora do CDI.
     *
     * A varredura para na primeira linha reprovada; no caminho feliz são duas leituras
     * de array por linha, sem consulta nem recálculo.
     *
     * @param  list<PuDailyCurveRowData>  $rows
     *
     * @throws PuNonOperationalProfileException
     */
    public function assertOperational(array $rows, string $context): void
    {
        foreach ($rows as $row) {
            $declared = $row->calculationMemory['calculation_profile'] ?? null;

            if ($declared === null) {
                $this->assertHistoricalEngine($row, $context);

                continue;
            }

            $profile = is_string($declared) ? PuCalculationProfile::tryFrom($declared) : null;

            if ($profile?->isOperational() === true) {
                continue;
            }

            throw new PuNonOperationalProfileException(sprintf(
                'A curva foi calculada no perfil "%s", que não pode alimentar %s. Recalcule no perfil contratual.',
                $profile?->label() ?? (is_string($declared) ? $declared : 'desconhecido'),
                $context,
            ));
        }
    }

    /**
     * Linha sem carimbo de perfil só passa se NÃO for da engine contratual corrente.
     *
     * @throws PuNonOperationalProfileException
     */
    private function assertHistoricalEngine(PuDailyCurveRowData $row, string $context): void
    {
        $engineVersion = $row->calculationMemory['engine_version'] ?? null;

        if ($engineVersion !== PuAuditLogService::ENGINE_VERSION) {
            return;
        }

        throw new PuNonOperationalProfileException(sprintf(
            'A curva declara a engine %s sem carimbo de perfil de cálculo na memória, então a sua procedência não pode ser verificada e ela não pode alimentar %s. Recalcule a curva.',
            PuAuditLogService::ENGINE_VERSION,
            $context,
        ));
    }
}
