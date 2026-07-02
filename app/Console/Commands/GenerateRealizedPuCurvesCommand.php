<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class GenerateRealizedPuCurvesCommand extends Command
{
    protected $signature = 'pu:curves:generate-realized
        {--emission= : Gera apenas para a emissao informada (ID)}';

    protected $description = 'Reprocessa a parte realizada das curvas de PU de CDI, incorporando o indice publicado mais recente. Curvas homologadas sao preservadas (puladas).';

    public function handle(PuCurveVersionService $versionService): int
    {
        $dispatched = 0;
        $skippedHomologated = 0;
        $skippedComplete = 0;

        $this->eligibleEmissions()->each(function (Emission $emission) use (
            $versionService,
            &$dispatched,
            &$skippedHomologated,
            &$skippedComplete,
        ): void {
            if ($versionService->hasHomologatedVersion($emission)) {
                $skippedHomologated++;

                return;
            }

            if (! $this->hasRealizedTailToExtend($emission)) {
                $skippedComplete++;

                return;
            }

            GeneratePuDailyCurveJob::dispatch($emission->id, null, false);
            $dispatched++;
        });

        $this->info(sprintf(
            'Curvas de PU realizadas: %d reprocessamento(s) enfileirado(s), %d homologada(s) preservada(s), %d ja completa(s).',
            $dispatched,
            $skippedHomologated,
            $skippedComplete,
        ));

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\LazyCollection<int, Emission>
     */
    private function eligibleEmissions(): \Illuminate\Support\LazyCollection
    {
        return Emission::query()
            ->where('status', 'active')
            ->whereHas('puParameter', fn (Builder $query): Builder => $query->where('indexer', PuIndexer::Cdi->value))
            ->when($this->option('emission'), fn (Builder $query): Builder => $query->whereKey($this->option('emission')))
            ->with('puParameter')
            ->lazyById();
    }

    /**
     * Só há o que reprocessar quando a curva ainda não alcançou a data final: nesse caso o CDI recém
     * publicado estende a parte realizada. Curvas que já cobrem todo o período não são reprocessadas
     * (evita versionamento desnecessário a cada dia).
     */
    private function hasRealizedTailToExtend(Emission $emission): bool
    {
        $curveEnd = $emission->puParameter?->curve_end_date;

        if ($curveEnd === null) {
            return false;
        }

        $lastCurveDate = $emission->puDailyCurves()->max('curve_date');

        if ($lastCurveDate === null) {
            return true;
        }

        return CarbonImmutable::parse((string) $lastCurveDate)
            ->lt(CarbonImmutable::instance($curveEnd));
    }
}
