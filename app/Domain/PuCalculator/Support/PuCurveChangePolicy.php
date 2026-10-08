<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Support;

use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuCurveChangeKind;
use Carbon\CarbonInterface;

/**
 * Matriz de versionamento da curva de PU, executável.
 *
 * Uma mudança de insumo vale para a versão conforme a sua natureza e a data a
 * partir da qual ela altera o cálculo, comparada com o último dia que a versão já
 * gravou:
 *
 *  - dado de mercado novo estende; corrigido no trecho gravado reprocessa;
 *  - termo contratual de base e identidade da engine valem desde o início da
 *    curva: sempre reprocessam;
 *  - evento, integralização e horizonte são posicionais: alcançando o trecho
 *    gravado reprocessam; só no futuro exigem versão nova ANTES da data afetada;
 *  - metadado operacional não tem impacto.
 *
 * Extensão, homologação, atualidade, auditoria e correção de índice decidem por
 * aqui -- nenhuma delas reimplementa a regra.
 */
final class PuCurveChangePolicy
{
    public static function decide(
        PuCurveChangeKind $kind,
        ?CarbonInterface $affectedFrom,
        ?CarbonInterface $lastPersistedDate,
    ): PuCurveChangeImpact {
        return match ($kind) {
            PuCurveChangeKind::OperationalMetadata => PuCurveChangeImpact::NoCurveImpact,
            PuCurveChangeKind::NewRealizedIndexObservation => PuCurveChangeImpact::ExtensionSafe,
            PuCurveChangeKind::EngineIdentity,
            PuCurveChangeKind::ContractualTerms => PuCurveChangeImpact::HistoricalReprocessRequired,
            PuCurveChangeKind::IndexRateCorrection,
            PuCurveChangeKind::CalendarContentRevision => self::reachesPersisted($affectedFrom, $lastPersistedDate)
                ? PuCurveChangeImpact::HistoricalReprocessRequired
                : PuCurveChangeImpact::ExtensionSafe,
            PuCurveChangeKind::Horizon,
            PuCurveChangeKind::ContractualEvent,
            PuCurveChangeKind::Integralization => self::reachesPersisted($affectedFrom, $lastPersistedDate)
                ? PuCurveChangeImpact::HistoricalReprocessRequired
                : PuCurveChangeImpact::FutureVersionRequired,
        };
    }

    /**
     * A mais restritiva de várias decisões.
     *
     * @param  list<PuCurveChangeImpact>  $impacts
     */
    public static function combine(array $impacts): PuCurveChangeImpact
    {
        $combined = PuCurveChangeImpact::NoCurveImpact;

        foreach ($impacts as $impact) {
            if ($impact->severity() > $combined->severity()) {
                $combined = $impact;
            }
        }

        return $combined;
    }

    /**
     * A matriz, cenário a cenário, como a política a decide para uma versão
     * gravada até `$lastPersisted`. Fonte única do relatório e do teste da matriz.
     *
     * @return list<array{change: string, kind: PuCurveChangeKind, affected: string, impact: PuCurveChangeImpact, historical: bool, future: bool, new_version: bool, reprocess: bool, homologation: bool, extension_allowed: string}>
     */
    public static function matrix(CarbonInterface $lastPersisted): array
    {
        $past = $lastPersisted->toImmutable()->subDays(30);
        $future = $lastPersisted->toImmutable()->addDays(30);
        $scenarios = [
            ['Novo CDI realizado', PuCurveChangeKind::NewRealizedIndexObservation, null],
            ['Correção histórica de CDI', PuCurveChangeKind::IndexRateCorrection, $past],
            ['Integralização futura já aprovada no retrato', null, null],
            ['Integralização futura fora do retrato aprovado', PuCurveChangeKind::Integralization, $future],
            ['Integralização retroativa', PuCurveChangeKind::Integralization, $past],
            ['Correção de quantidade integralizada', PuCurveChangeKind::Integralization, $past],
            ['Alteração de spread (termo de base)', PuCurveChangeKind::ContractualTerms, null],
            ['Alteração de spread com vigência futura (evento)', PuCurveChangeKind::ContractualEvent, $future],
            ['Alteração de base de dias úteis', PuCurveChangeKind::ContractualTerms, null],
            ['Alteração do modo de busca do índice', PuCurveChangeKind::ContractualTerms, null],
            ['Alteração da defasagem do índice', PuCurveChangeKind::ContractualTerms, null],
            ['Alteração do calendário contratual', PuCurveChangeKind::ContractualTerms, null],
            ['Alteração do vencimento (futuro)', PuCurveChangeKind::Horizon, $future],
            ['Alteração do vencimento (antes do último dia gravado)', PuCurveChangeKind::Horizon, $past],
            ['Evento incluído no futuro', PuCurveChangeKind::ContractualEvent, $future],
            ['Evento incluído no passado', PuCurveChangeKind::ContractualEvent, $past],
            ['Evento alterado (futuro)', PuCurveChangeKind::ContractualEvent, $future],
            ['Evento cancelado (futuro)', PuCurveChangeKind::ContractualEvent, $future],
            ['Evento cancelado (passado)', PuCurveChangeKind::ContractualEvent, $past],
            ['Evento movido para trás do último dia gravado', PuCurveChangeKind::ContractualEvent, $past],
            ['Somente metadado operacional', PuCurveChangeKind::OperationalMetadata, null],
        ];

        return array_map(function (array $scenario) use ($lastPersisted): array {
            [$change, $kind, $affected] = $scenario;
            $impact = $kind === null
                ? PuCurveChangeImpact::NoCurveImpact
                : self::decide($kind, $affected, $lastPersisted);

            return [
                'change' => $change,
                'kind' => $kind ?? PuCurveChangeKind::Integralization,
                'affected' => $affected === null ? 'início da curva / n.a.' : $affected->toDateString(),
                'impact' => $impact,
                'historical' => $impact === PuCurveChangeImpact::HistoricalReprocessRequired,
                'future' => $impact === PuCurveChangeImpact::FutureVersionRequired
                    || $impact === PuCurveChangeImpact::ExtensionSafe,
                'new_version' => $impact->requiresNewVersion(),
                'reprocess' => $impact->requiresReprocessing(),
                'homologation' => $impact->requiresHomologation(),
                'extension_allowed' => match ($impact) {
                    PuCurveChangeImpact::NoCurveImpact, PuCurveChangeImpact::ExtensionSafe => 'sim',
                    PuCurveChangeImpact::FutureVersionRequired => 'até a véspera da data afetada',
                    PuCurveChangeImpact::HistoricalReprocessRequired => 'não',
                },
            ];
        }, $scenarios);
    }

    /**
     * Sem data conhecida, a mudança é tratada como se alcançasse o passado: na
     * dúvida, nada é anexado.
     */
    private static function reachesPersisted(?CarbonInterface $affectedFrom, ?CarbonInterface $lastPersistedDate): bool
    {
        if ($affectedFrom === null || $lastPersistedDate === null) {
            return true;
        }

        return $affectedFrom->toDateString() <= $lastPersistedDate->toDateString();
    }
}
