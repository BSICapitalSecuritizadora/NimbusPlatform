<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use Carbon\CarbonImmutable;

/**
 * Um PU lido para uma data, com a fonte de onde ele veio.
 *
 * `date` é sempre a data a que o PU pertence -- a POSIÇÃO; `requestedDate` é a
 * data que quem leu pediu. Quando as duas diferem, o PU foi CARREGADO de uma
 * data anterior -- numa curva oficial, isso quer dizer que a curva realizada
 * ainda não chega à data pedida, e o valor não pode ser apresentado como o PU
 * daquela data.
 *
 * Leitura da curva oficial traz também a situação dela (`officialStatus`) no
 * momento da leitura: é ela que diz se o PU carregado é o mais recente que
 * podia existir ({@see self::standsForRequestedDate()}).
 */
final readonly class PuReading
{
    public const SOURCE_OFFICIAL_CURVE = 'official_curve';

    public const SOURCE_PU_HISTORY = 'pu_history';

    public function __construct(
        public CarbonImmutable $date,
        public string $unitValue,
        public string $source,
        public ?string $calculationVersion = null,
        public ?CarbonImmutable $requestedDate = null,
        public ?PuOfficialCurveStatus $officialStatus = null,
    ) {}

    /**
     * O PU pertence a uma data anterior à pedida.
     */
    public function isCarriedForward(): bool
    {
        return $this->requestedDate !== null
            && $this->date->toDateString() < $this->requestedDate->toDateString();
    }

    public function fromOfficialCurve(): bool
    {
        return $this->source === self::SOURCE_OFFICIAL_CURVE;
    }

    public function freshness(): ?PuOfficialCurveFreshness
    {
        return $this->officialStatus?->freshness;
    }

    /**
     * O PU pode ser usado como o PU da data pedida por quem não mostra a data a
     * que ele pertence (saldo devedor das garantias, "PU atual" do site).
     *
     * O PU da própria data, sempre. O carregado de uma data anterior, só da curva
     * oficial em dia (atual, completa ou sem acompanhamento diário): a curva
     * incorporou todo o índice realizado que existe, e o PU da data pedida ainda
     * não podia existir. Com a curva atrasada, sem índice exigido, com a extensão
     * falhando ou à espera de reprocessamento, o carregado é mais velho do que
     * devia, e usá-lo como da data pedida muda o sentido do número.
     *
     * Histórico de PU (sem curva oficial) não tem atualidade por índice: segue a
     * regra da Fase 2 e responde pela data com a posição exposta em `date`.
     */
    public function standsForRequestedDate(): bool
    {
        if (! $this->isCarriedForward() || ! $this->fromOfficialCurve()) {
            return true;
        }

        return $this->officialStatus?->freshness->isUpToDate() ?? false;
    }
}
