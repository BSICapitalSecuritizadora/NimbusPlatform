<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Jobs\ExtendPuDailyCurveJob;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

class GenerateRealizedPuCurvesCommand extends Command
{
    protected $signature = 'pu:curves:generate-realized
        {--emission= : Processa apenas a emissao informada (ID)}';

    protected $description = 'Estende a curva de PU de CDI vigente com o indice publicado mais recente, anexando so os dias novos. Sem curva vigente, gera a curva inteira.';

    public function handle(): int
    {
        $extensions = 0;
        $generations = 0;
        $skippedComplete = 0;

        $this->eligibleEmissions()->each(function (Emission $emission) use (
            &$extensions,
            &$generations,
            &$skippedComplete,
        ): void {
            $version = $emission->currentPuCurveVersion();

            if (! $version instanceof EmissionPuCurveVersion) {
                GeneratePuDailyCurveJob::dispatch($emission->id, null, false);
                $generations++;

                return;
            }

            if (! $this->hasRealizedTailToExtend($emission, $version)) {
                $skippedComplete++;

                return;
            }

            // A extensão só anexa dias novos e nunca troca a versão vigente, então
            // curvas homologadas e promovidas também avançam: o trecho revisado
            // permanece intacto e o recálculo prova, a cada dia, que ele não mudou.
            ExtendPuDailyCurveJob::dispatch($emission->id);
            $extensions++;
        });

        $this->info(sprintf(
            'Curvas de PU realizadas: %d extensao(oes) enfileirada(s), %d geracao(oes) completa(s) enfileirada(s), %d ja completa(s).',
            $extensions,
            $generations,
            $skippedComplete,
        ));

        return self::SUCCESS;
    }

    /**
     * @return LazyCollection<int, Emission>
     */
    private function eligibleEmissions(): LazyCollection
    {
        return Emission::query()
            ->where('status', 'active')
            ->whereHas('puParameter', fn (Builder $query): Builder => $query->where('indexer', PuIndexer::Cdi->value))
            ->when($this->option('emission'), fn (Builder $query): Builder => $query->whereKey($this->option('emission')))
            ->with('puParameter')
            ->lazyById();
    }

    /**
     * Só há o que estender quando a curva vigente ainda não alcançou a data final:
     * nesse caso o CDI recém-publicado acrescenta dias realizados. Curvas que já
     * cobrem todo o período não recebem nada.
     */
    private function hasRealizedTailToExtend(Emission $emission, EmissionPuCurveVersion $version): bool
    {
        $curveEnd = $emission->puParameter?->curve_end_date;

        if ($curveEnd === null) {
            return false;
        }

        $lastCurveDate = $version->dailyCurves()->max('curve_date');

        if ($lastCurveDate === null) {
            return true;
        }

        return CarbonImmutable::parse((string) $lastCurveDate)
            ->lt(CarbonImmutable::instance($curveEnd));
    }
}
