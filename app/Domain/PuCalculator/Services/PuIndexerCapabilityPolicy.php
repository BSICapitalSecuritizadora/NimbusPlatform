<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Exceptions\PuIndexerCapabilityException;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuParameter;

/**
 * Portão único de elegibilidade operacional por indexador (Fase 6, P0-05).
 *
 * Implementar o indexador na engine não o aprova. Só o indexador declarado
 * homologado para uso operacional ({@see PuIndexer::isHomologated()}: hoje CDI e
 * prefixado) grava curva, valida, homologa, estende a oficial e calcula
 * obrigações. Os demais -- IPCA -- só simulam: a engine continua disponível em
 * memória, para conferência contra gabarito, e nada do que ela calcula chega a
 * uma versão, ao PU oficial ou ao esperado das obrigações.
 *
 * Fica nas fronteiras de domínio por onde todo caminho passa -- pré-requisitos e
 * gravação da curva, candidata, validação, homologação, extensão oficial e
 * cronograma das obrigações --, então tela, comando, job e chamada direta ao
 * serviço recebem a mesma recusa. Indexador desconhecido é recusado: na dúvida,
 * nada vira oficial.
 */
final class PuIndexerCapabilityPolicy
{
    public function __construct(
        private readonly PuCurveInputSnapshotService $snapshots,
    ) {}

    public function allows(?PuIndexer $indexer, PuIndexerCapability $capability): bool
    {
        if (! $indexer instanceof PuIndexer) {
            return false;
        }

        if ($capability === PuIndexerCapability::Simulation) {
            return true;
        }

        return $indexer->isHomologated();
    }

    /**
     * @throws PuIndexerCapabilityException
     */
    public function assert(?PuIndexer $indexer, PuIndexerCapability $capability, ?int $emissionId = null): void
    {
        if ($this->allows($indexer, $capability)) {
            return;
        }

        throw $this->refusal($indexer, $capability, $emissionId);
    }

    /**
     * @throws PuIndexerCapabilityException
     */
    public function assertForEmission(Emission $emission, PuIndexerCapability $capability): void
    {
        $this->assert($this->emissionIndexer($emission), $capability, (int) $emission->getKey());
    }

    /**
     * Pela versão: o indexador que ela APROVOU (retrato de insumos), depois o
     * registrado na geração, e só por último o parâmetro vivo.
     *
     * @throws PuIndexerCapabilityException
     */
    public function assertForVersion(EmissionPuCurveVersion $version, PuIndexerCapability $capability): void
    {
        $this->assert($this->versionIndexer($version), $capability, (int) $version->emission_id);
    }

    public function emissionIndexer(Emission $emission): ?PuIndexer
    {
        $parameter = $emission->relationLoaded('puParameter')
            ? $emission->puParameter
            : EmissionPuParameter::query()->where('emission_id', $emission->getKey())->first();

        return $parameter?->indexer_enum;
    }

    public function versionIndexer(EmissionPuCurveVersion $version): ?PuIndexer
    {
        try {
            $approved = $this->snapshots->forVersion($version);
        } catch (\Throwable) {
            $approved = null;
        }

        if ($approved instanceof PuCurveInputSnapshot && ($fromSnapshot = PuIndexer::tryFrom((string) ($approved->terms()['indexer'] ?? ''))) !== null) {
            return $fromSnapshot;
        }

        $recorded = is_array($version->parameters_snapshot) ? ($version->parameters_snapshot['indexer'] ?? null) : null;

        if (is_string($recorded) && ($fromGeneration = PuIndexer::tryFrom($recorded)) !== null) {
            return $fromGeneration;
        }

        return EmissionPuParameter::query()->where('emission_id', $version->emission_id)->first()?->indexer_enum;
    }

    /**
     * A matriz de capacidades, para o retrato operacional e o relatório.
     *
     * @return array<string, array<string, bool>>
     */
    public function matrix(): array
    {
        $matrix = [];

        foreach (PuIndexer::cases() as $indexer) {
            foreach (PuIndexerCapability::cases() as $capability) {
                $matrix[$indexer->value][$capability->value] = $this->allows($indexer, $capability);
            }
        }

        return $matrix;
    }

    public function refusal(?PuIndexer $indexer, PuIndexerCapability $capability, ?int $emissionId = null): PuIndexerCapabilityException
    {
        if (! $indexer instanceof PuIndexer) {
            return new PuIndexerCapabilityException(
                reasonCode: PuIndexerCapabilityException::REASON_UNKNOWN,
                indexer: null,
                capability: $capability,
                emissionId: $emissionId,
                nextAction: 'Configure os parâmetros de PU da emissão com um indexador homologado.',
                message: sprintf(
                    '%s: o indexador da emissão%s não foi identificado, e %s só é permitida para indexador com homologação operacional.',
                    PuIndexerCapabilityException::REASON_UNKNOWN,
                    $emissionId !== null ? ' #'.$emissionId : '',
                    mb_strtolower($capability->label()),
                ),
            );
        }

        return new PuIndexerCapabilityException(
            reasonCode: PuIndexerCapabilityException::REASON_NOT_HOMOLOGATED,
            indexer: $indexer,
            capability: $capability,
            emissionId: $emissionId,
            nextAction: sprintf(
                'Concluir a homologação financeira e operacional do indexador %s antes de usá-lo na operação; até lá, use só a simulação.',
                $indexer->value,
            ),
            message: sprintf(
                '%s: o indexador %s ainda não tem homologação operacional, e %s está bloqueada%s. Nada foi gravado; a simulação continua disponível.',
                PuIndexerCapabilityException::REASON_NOT_HOMOLOGATED,
                $indexer->value,
                mb_strtolower($capability->label()),
                $emissionId !== null ? ' na emissão #'.$emissionId : '',
            ),
        );
    }
}
