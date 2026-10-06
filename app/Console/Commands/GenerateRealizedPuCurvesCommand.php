<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
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

    protected $description = 'Estende com o indice realizado mais recente a curva de PU OFICIAL (homologada) e, separadamente, a versao de trabalho de CDI, anexando so os dias novos. Sem nenhuma versao utilizavel, gera a curva inteira (que nasce nao oficial).';

    public function handle(PuCurveExtensionService $extensions): int
    {
        $extensionsQueued = 0;
        $officialExtensions = 0;
        $generations = 0;
        $skippedComplete = 0;
        $skippedAttempts = 0;

        $this->eligibleEmissions()->each(function (Emission $emission) use (
            $extensions,
            &$extensionsQueued,
            &$officialExtensions,
            &$generations,
            &$skippedComplete,
            &$skippedAttempts,
        ): void {
            // A curva oficial é a homologada vigente; a de trabalho é a utilizável mais
            // recente que ainda não é oficial. Uma tentativa nova com erro ou em
            // processamento não esconde nenhuma das duas, e a de trabalho nunca toma o
            // lugar da oficial como alvo da extensão.
            $official = $emission->officialPuCurveVersion();
            $working = $extensions->workingVersion($emission);

            if (! $official instanceof EmissionPuCurveVersion && ! $working instanceof EmissionPuCurveVersion) {
                // Sem versão utilizável, uma tentativa em andamento ou que falhou
                // fica para decisão humana, como antes: a rotina não a repete.
                if (in_array($emission->latestPuCurveVersion()->first()?->status, [
                    PuCurveStatus::Pending,
                    PuCurveStatus::Processing,
                    PuCurveStatus::Error,
                ], true)) {
                    $skippedAttempts++;

                    return;
                }

                // Gerar não publica: a versão nasce `generated` e só a homologação a
                // torna oficial.
                GeneratePuDailyCurveJob::dispatch($emission->id, null, false);
                $generations++;

                return;
            }

            $officialHasTail = $official instanceof EmissionPuCurveVersion
                && $this->hasRealizedTailToExtend($emission, $official);
            $workingHasTail = $working instanceof EmissionPuCurveVersion
                && $this->hasRealizedTailToExtend($emission, $working);

            if (! $officialHasTail && ! $workingHasTail) {
                $skippedComplete++;

                return;
            }

            // A extensão só anexa dias realizados novos e nunca troca a versão: o
            // trecho homologado permanece intacto e o recálculo prova, a cada dia,
            // que ele não mudou.
            ExtendPuDailyCurveJob::dispatch($emission->id);
            $extensionsQueued++;

            if ($officialHasTail) {
                $officialExtensions++;
            }
        });

        $this->info(sprintf(
            'Curvas de PU realizadas: %d extensao(oes) enfileirada(s) (%d com curva oficial), %d geracao(oes) completa(s) enfileirada(s), %d ja completa(s), %d sem versao utilizavel (em processamento ou com erro).',
            $extensionsQueued,
            $officialExtensions,
            $generations,
            $skippedComplete,
            $skippedAttempts,
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
     * Só há o que estender quando a versão ainda não alcançou a data final: nesse
     * caso o CDI recém-publicado acrescenta dias realizados. Curvas que já cobrem
     * todo o período não recebem nada.
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
