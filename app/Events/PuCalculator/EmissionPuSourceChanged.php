<?php

declare(strict_types=1);

namespace App\Events\PuCalculator;

use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\ImportPuHistoriesFromSpreadsheet;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Enums\PuSourceChange;
use App\Listeners\Guarantees\MarkGuaranteeCompetencesOnPuSourceChange;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A fonte de PU de uma Emissão mudou de uma vez, e com ela o saldo devedor que
 * as competências de garantias gravaram.
 *
 * Disparado por três atos, todos em `app/Actions`:
 *
 * - {@see HomologatePuCurve}: a curva homologada passa a ser a oficial;
 * - {@see InvalidatePuCurve}: a oficial deixa de valer e o PU volta à anterior
 *   ou ao Histórico de PU;
 * - {@see ImportPuHistoriesFromSpreadsheet}: o Histórico importado responde
 *   pelas emissões sem curva homologada.
 *
 * Nada em `app/Domain/PuCalculator` dispara este evento, de propósito: o motor
 * de PU não sabe que garantias existem, e instrumentar a extensão diária, a
 * persistência da curva ou a projeção legada espalharia uma preocupação de
 * garantias pelo cálculo -- a projeção grava linha a linha e dispararia
 * milhares de verificações. Esses caminhos são pegos pela verificação diária
 * (`guarantees:mark-outdated-competences`).
 *
 * Despachado só depois do commit: uma homologação desfeita não trocou fonte
 * nenhuma, e não pode marcar competência alguma. Quem escuta é
 * {@see MarkGuaranteeCompetencesOnPuSourceChange}.
 */
class EmissionPuSourceChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $emissionId,
        public readonly PuSourceChange $change,
        public readonly ?string $calculationVersion = null,
        public readonly ?int $causerId = null,
    ) {}

    /**
     * O motivo que a competência marcada vai mostrar: "Curva de PU v7 homologada".
     */
    public function reason(): string
    {
        return $this->change->describe($this->calculationVersion);
    }
}
