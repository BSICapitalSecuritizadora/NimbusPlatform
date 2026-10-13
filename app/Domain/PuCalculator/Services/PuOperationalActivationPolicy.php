<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;
use App\Domain\PuCalculator\Support\PuOperationsConfig;
use App\Models\Emission;
use App\Models\EmissionPuOperationalStatusChange;
use App\Models\EmissionPuParameter;

/**
 * Portão de ativação operacional do PU (Fase 7).
 *
 * Uma emissão `active` com parâmetros CDI não está, por isso, no pipeline que
 * grava curva: "configurada", "elegível" e "autorizada" são coisas distintas. Só
 * entra quem passa nos dois portões:
 *
 *  - global: as operações do PU estão ligadas NESTE ambiente
 *    (`PU_OPERATIONS_ENABLED`, desligado quando ausente ou ilegível --
 *    {@see PuOperationsConfig});
 *  - por emissão: a última decisão gravada no livro de ativação é `activated`
 *    ({@see PuOperationalActivationService}). Sem decisão a emissão está inativa;
 *    nenhuma migration, deploy, tela aberta ou rotina a ativa.
 *
 * E, à parte, o portão do indexador ({@see PuIndexerCapabilityPolicy}) e os
 * pré-requisitos contratuais de cada degrau, que continuam valendo.
 *
 * Fica nas mesmas fronteiras de domínio do portão do indexador -- gravação de
 * curva (de trabalho e candidata), promoção, homologação e extensão -- para que
 * tela, comando, job e chamada direta recebam a mesma recusa. A checagem que vale
 * é a feita DENTRO da transação de escrita, depois de travar a emissão: a
 * ativação e a suspensão travam a mesma linha primeiro, então uma suspensão que
 * comitou antes é vista e uma que chega depois espera. A leitura do livro é
 * travada (`LOCK IN SHARE MODE`) porque, no REPEATABLE READ do MySQL, uma leitura
 * comum devolveria o retrato tirado no início da transação -- de antes da
 * suspensão.
 *
 * Fora do portão, de propósito: simulação em memória, validação contra planilha
 * de uma versão já gravada, invalidação (ato de segurança, sempre possível),
 * atualização das obrigações a partir da oficial já homologada, sincronização e
 * correção de índice (dado de mercado) e o monitoramento.
 */
final class PuOperationalActivationPolicy
{
    public function globallyEnabled(): bool
    {
        return PuOperationsConfig::enabled();
    }

    /**
     * Situação operacional da emissão. `lockForShare` só dentro de uma transação
     * de escrita que já travou a emissão.
     */
    public function status(Emission|int $emission, bool $lockForShare = false): PuOperationalActivationStatus
    {
        $emissionId = $emission instanceof Emission ? (int) $emission->getKey() : $emission;
        $latest = $this->latestChange($emissionId, $lockForShare);

        if ($latest instanceof EmissionPuOperationalStatusChange) {
            return $latest->status;
        }

        $configured = $emission instanceof Emission && $emission->relationLoaded('puParameter')
            ? $emission->puParameter instanceof EmissionPuParameter
            : EmissionPuParameter::query()->where('emission_id', $emissionId)->exists();

        return $configured ? PuOperationalActivationStatus::Inactive : PuOperationalActivationStatus::NotConfigured;
    }

    /**
     * Situação de várias emissões numa consulta (monitor, rotina diária, preflight).
     *
     * @param  list<int>  $emissionIds
     * @return array<int, PuOperationalActivationStatus>
     */
    public function statuses(array $emissionIds): array
    {
        if ($emissionIds === []) {
            return [];
        }

        $latestIds = EmissionPuOperationalStatusChange::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('emission_id', $emissionIds)
            ->groupBy('emission_id');
        $recorded = EmissionPuOperationalStatusChange::query()
            ->whereIn('id', $latestIds)
            ->get(['id', 'emission_id', 'status'])
            ->mapWithKeys(fn (EmissionPuOperationalStatusChange $change): array => [(int) $change->emission_id => $change->status]);
        $configured = array_flip(array_map('intval', EmissionPuParameter::query()
            ->whereIn('emission_id', $emissionIds)
            ->pluck('emission_id')
            ->all()));
        $statuses = [];

        foreach ($emissionIds as $emissionId) {
            $statuses[$emissionId] = $recorded->get($emissionId)
                ?? (isset($configured[$emissionId]) ? PuOperationalActivationStatus::Inactive : PuOperationalActivationStatus::NotConfigured);
        }

        return $statuses;
    }

    public function latestChange(int $emissionId, bool $lockForShare = false): ?EmissionPuOperationalStatusChange
    {
        return EmissionPuOperationalStatusChange::query()
            ->where('emission_id', $emissionId)
            ->orderByDesc('id')
            ->when($lockForShare, fn ($query) => $query->sharedLock())
            ->first();
    }

    /**
     * A emissão pode executar o degrau operacional agora: interruptor global
     * ligado e emissão ativada.
     */
    public function allows(Emission|int $emission, bool $lockForShare = false): bool
    {
        return $this->globallyEnabled() && $this->status($emission, $lockForShare)->isOperational();
    }

    /**
     * @throws PuOperationalActivationException
     */
    public function assert(Emission|int $emission, PuIndexerCapability $capability, bool $lockForShare = false): void
    {
        $refusal = $this->refusalFor($emission, $capability, $lockForShare);

        if ($refusal instanceof PuOperationalActivationException) {
            throw $refusal;
        }
    }

    /**
     * A recusa para o degrau, ou nula quando ele pode seguir. Para quem registra a
     * recusa sem lançar (a extensão diária devolve um resultado).
     */
    public function refusalFor(Emission|int $emission, PuIndexerCapability $capability, bool $lockForShare = false): ?PuOperationalActivationException
    {
        $emissionId = $emission instanceof Emission ? (int) $emission->getKey() : $emission;

        if (! $this->globallyEnabled()) {
            return new PuOperationalActivationException(
                reasonCode: PuOperationalActivationException::REASON_GLOBALLY_DISABLED,
                emissionId: $emissionId,
                status: null,
                message: sprintf(
                    '%s: as operações do PU estão desligadas neste ambiente (PU_OPERATIONS_ENABLED), e %s não roda. Nada foi gravado; a simulação continua disponível.',
                    PuOperationalActivationException::REASON_GLOBALLY_DISABLED,
                    mb_strtolower($capability->label()),
                ),
            );
        }

        $status = $this->status($emission, $lockForShare);

        if ($status->isOperational()) {
            return null;
        }

        [$reason, $explanation] = match ($status) {
            PuOperationalActivationStatus::NotConfigured => [PuOperationalActivationException::REASON_NOT_CONFIGURED, 'não tem parâmetros de PU'],
            PuOperationalActivationStatus::Suspended => [PuOperationalActivationException::REASON_SUSPENDED, 'está com a operação do PU suspensa'],
            default => [PuOperationalActivationException::REASON_NOT_ACTIVATED, 'não teve a operação do PU ativada (estar ativa no cadastro e ter parâmetros não ativa)'],
        };

        return new PuOperationalActivationException(
            reasonCode: $reason,
            emissionId: $emissionId,
            status: $status,
            message: sprintf(
                '%s: a emissão #%d %s, e %s exige ativação operacional autorizada. Nada foi gravado; a simulação continua disponível.',
                $reason,
                $emissionId,
                $explanation,
                mb_strtolower($capability->label()),
            ),
        );
    }

    /**
     * Registrar uma liquidação real: interruptor global ligado e emissão que já
     * entrou no pipeline (ativada ou suspensa). Suspender a geração não apaga o
     * que aconteceu no mundo: a liquidação de uma obrigação que já existe continua
     * registrável. Antes da ativação não há obrigação a liquidar.
     *
     * @throws PuOperationalActivationException
     */
    public function assertSettlementRecording(int $emissionId, bool $lockForShare = false): void
    {
        if (! $this->globallyEnabled()) {
            throw new PuOperationalActivationException(
                reasonCode: PuOperationalActivationException::REASON_GLOBALLY_DISABLED,
                emissionId: $emissionId,
                status: null,
                message: sprintf(
                    '%s: as operações do PU estão desligadas neste ambiente (PU_OPERATIONS_ENABLED); nenhuma liquidação foi registrada.',
                    PuOperationalActivationException::REASON_GLOBALLY_DISABLED,
                ),
            );
        }

        $status = $this->status($emissionId, $lockForShare);

        if ($status->wasEverActivated()) {
            return;
        }

        throw new PuOperationalActivationException(
            reasonCode: $status === PuOperationalActivationStatus::NotConfigured
                ? PuOperationalActivationException::REASON_NOT_CONFIGURED
                : PuOperationalActivationException::REASON_NOT_ACTIVATED,
            emissionId: $emissionId,
            status: $status,
            message: sprintf(
                '%s: a emissão #%d nunca teve a operação do PU ativada; não há liquidação real a registrar antes da ativação.',
                PuOperationalActivationException::REASON_NOT_ACTIVATED,
                $emissionId,
            ),
        );
    }
}
