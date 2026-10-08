<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuSettlementActor;
use App\Domain\PuCalculator\DTOs\PuSettlementCorrectionData;
use App\Domain\PuCalculator\DTOs\PuSettlementData;
use App\Domain\PuCalculator\DTOs\PuSettlementResult;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuSettlementConflictKind;
use App\Domain\PuCalculator\Enums\PuSettlementConflictStatus;
use App\Domain\PuCalculator\Enums\PuSettlementEntryType;
use App\Domain\PuCalculator\Enums\PuSettlementOutcome;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Livro de liquidações do PU (Fase 5): a fronteira por onde um conector (B3,
 * banco) ou uma pessoa registra o que de fato foi liquidado.
 *
 * Regras, todas aplicadas aqui -- não na tela:
 *
 *  - liquidação é FECHADA: uma obrigação está liquidada ou não. Valor diferente do
 *    esperado é divergência da conciliação, nunca parcela, saldo residual, nova
 *    obrigação, multa ou redução de principal; a liquidação não toca curva,
 *    principal, spread, vencimento nem evento contratual;
 *  - o fato é registrado mesmo quando o esperado não é confiável agora (aguardando
 *    índice, reprocessamento): registrar o fato e interpretá-lo são coisas
 *    separadas, e a conciliação fica indeterminada até o esperado voltar;
 *  - a mesma mensagem (mesma origem e referência externa, mesmos dados) é
 *    idempotente; com dados diferentes, ou para obrigação já liquidada por outro
 *    fato, vira conflito em aberto -- nada é sobrescrito nem escolhido em silêncio;
 *  - correção e estorno são lançamentos novos que encerram o anterior; o original
 *    fica no livro com quem, quando e por quê;
 *  - registrar exige `pu.settlement.record`; corrigir, estornar e decidir conflito
 *    exigem `pu.settlement.correct`. Integração de sistema se identifica pelo nome
 *    ({@see PuSettlementActor}).
 *
 * Concorrência: a emissão é travada primeiro (a mesma ordem da homologação e da
 * extensão), depois a obrigação e as liquidações dela. Duas mensagens iguais ou
 * conflitantes, ou duas correções, se enfileiram; o banco ainda garante uma
 * liquidação ativa por obrigação, uma por chave de ingestão e um sucessor por
 * lançamento. Uma violação de unicidade que escape repete a operação uma vez, e a
 * repetição cai no caminho idempotente ou de conflito.
 */
final class PuSettlementService
{
    private const MONEY_PATTERN = '/^\d{1,13}(\.\d{1,2})?$/';

    public function __construct(
        private readonly PuFinancialObligationService $obligations,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function record(PuSettlementData $data, PuSettlementActor $actor): PuSettlementResult
    {
        $this->authorize($actor, AccessPermission::PuSettlementRecord);
        $payload = $this->payload($data);
        $problem = $this->invalid($data->settlementDate, $data->amount, $data->components, $data->currency)
            ?? ($data->obligationId === null && ($data->obligationType === null || blank($data->contractualDate))
                ? 'Informe a obrigação (id, ou natureza e data contratual) que a liquidação fecha.'
                : null);

        if ($problem !== null) {
            return $this->reject(Emission::query()->find($data->emissionId), $problem, $payload, $actor);
        }

        return $this->retryingOnce(fn (): PuSettlementResult => DB::transaction(function () use ($data, $actor, $payload): PuSettlementResult {
            $emission = Emission::query()->whereKey($data->emissionId)->lockForUpdate()->first();

            if (! $emission instanceof Emission) {
                return $this->reject(null, 'A emissão da liquidação não existe.', $payload, $actor);
            }

            $obligation = $this->locateObligation($emission, $data);

            if (! $obligation instanceof EmissionPuObligation) {
                return $this->reject($emission, 'Nenhuma obrigação do cronograma oficial corresponde a esta liquidação; ela não foi registrada.', $payload, $actor);
            }

            $amount = $this->money($data->amount);
            $components = $this->components($data->components);
            $fingerprint = $this->fingerprint($obligation->id, $data->settlementDate, $amount, $data->currency, $components);
            $ingestionKey = $data->ingestionKey();

            if ($ingestionKey !== null) {
                $original = EmissionPuSettlement::query()->where('ingestion_key', $ingestionKey)->lockForUpdate()->first();

                if ($original instanceof EmissionPuSettlement) {
                    if ((int) $original->obligation_id !== (int) $obligation->id) {
                        return $this->conflict($obligation, PuSettlementConflictKind::ReferenceReusedForOtherObligation, $original, $data, $payload, $fingerprint, $actor);
                    }

                    $head = $this->lineageHead($original);

                    if (hash_equals((string) $original->payload_fingerprint, $fingerprint)
                        || ($head->payload_fingerprint !== null && hash_equals((string) $head->payload_fingerprint, $fingerprint))) {
                        return new PuSettlementResult(PuSettlementOutcome::Duplicate, $head, obligation: $obligation, reason: 'Esta liquidação já estava registrada.');
                    }

                    return $this->conflict($obligation, PuSettlementConflictKind::ReferenceDataMismatch, $head->isActive() ? $head : $original, $data, $payload, $fingerprint, $actor);
                }
            }

            $active = EmissionPuSettlement::query()
                ->where('obligation_id', $obligation->id)
                ->where('status', PuSettlementStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($active instanceof EmissionPuSettlement) {
                if ($ingestionKey === null && $active->ingestion_key === null && hash_equals((string) $active->payload_fingerprint, $fingerprint)) {
                    return new PuSettlementResult(PuSettlementOutcome::Duplicate, $active, obligation: $obligation, reason: 'Esta liquidação já estava registrada.');
                }

                return $this->conflict($obligation, PuSettlementConflictKind::ObligationAlreadySettled, $active, $data, $payload, $fingerprint, $actor);
            }

            $settlement = EmissionPuSettlement::query()->create([
                'emission_id' => $emission->id,
                'obligation_id' => $obligation->id,
                'entry_type' => PuSettlementEntryType::Settlement,
                'status' => PuSettlementStatus::Active,
                'settlement_date' => $data->settlementDate,
                'amount' => $amount,
                'currency' => $data->currency,
                'components' => $components,
                'source' => $data->source,
                'external_reference' => $this->reference($data->externalReference),
                'ingestion_key' => $ingestionKey,
                'payload_fingerprint' => $fingerprint,
                'expected_calculation_id' => $obligation->current_calculation_id,
                'recorded_by' => $actor->userId(),
                'recorded_via' => $actor->via(),
                'recorded_at' => now(),
            ]);

            $this->obligations->reconcile($obligation, 'settlement_recorded');
            $this->auditLog->logSettlementRecorded($settlement);

            return new PuSettlementResult(PuSettlementOutcome::Recorded, $settlement, obligation: $obligation->fresh());
        }));
    }

    /**
     * Corrige a liquidação vigente com um lançamento novo; a original fica no livro
     * como corrigida.
     */
    public function correct(int $settlementId, PuSettlementCorrectionData $data, string $reason, PuSettlementActor $actor): PuSettlementResult
    {
        $this->authorize($actor, AccessPermission::PuSettlementCorrect);
        $payload = ['settlement_id' => $settlementId, 'settlement_date' => $data->settlementDate, 'amount' => $data->amount, 'components' => $data->components, 'currency' => $data->currency, 'reason' => $reason];
        $known = EmissionPuSettlement::query()->find($settlementId);
        $problem = blank($reason)
            ? 'A correção de liquidação registra o motivo.'
            : $this->invalid($data->settlementDate, $data->amount, $data->components, $data->currency);

        if ($problem !== null || ! $known instanceof EmissionPuSettlement) {
            return $this->reject($known?->emission, $problem ?? 'A liquidação a corrigir não existe.', $payload, $actor);
        }

        return $this->retryingOnce(fn (): PuSettlementResult => DB::transaction(function () use ($known, $data, $reason, $actor, $payload): PuSettlementResult {
            [$obligation, $settlement] = $this->lockSettlement($known);

            if (! $settlement->isActive()) {
                return $this->reject($obligation->emission, 'Só a liquidação vigente pode ser corrigida; esta já foi corrigida ou estornada.', $payload, $actor);
            }

            $amount = $this->money($data->amount);
            $components = $this->components($data->components);
            $fingerprint = $this->fingerprint($obligation->id, $data->settlementDate, $amount, $data->currency, $components);

            if (hash_equals((string) $settlement->payload_fingerprint, $fingerprint)) {
                return $this->reject($obligation->emission, 'A correção repete os mesmos dados da liquidação vigente.', $payload, $actor);
            }

            $correction = $this->appendCorrection($settlement, $obligation, $data->settlementDate, $amount, $data->currency, $components, $this->reference($data->externalReference), $fingerprint, trim($reason), $actor);

            $this->obligations->reconcile($obligation, 'settlement_corrected');
            $this->auditLog->logSettlementCorrected($settlement->fresh(), $correction);

            return new PuSettlementResult(PuSettlementOutcome::Recorded, $correction, obligation: $obligation->fresh());
        }));
    }

    /**
     * Estorna a liquidação vigente: a obrigação volta a não liquidada e o fato
     * estornado fica no livro.
     */
    public function reverse(int $settlementId, string $reason, PuSettlementActor $actor): PuSettlementResult
    {
        $this->authorize($actor, AccessPermission::PuSettlementCorrect);
        $payload = ['settlement_id' => $settlementId, 'reason' => $reason];
        $known = EmissionPuSettlement::query()->find($settlementId);

        if (blank($reason) || ! $known instanceof EmissionPuSettlement) {
            return $this->reject($known?->emission, blank($reason) ? 'O estorno de liquidação registra o motivo.' : 'A liquidação a estornar não existe.', $payload, $actor);
        }

        return $this->retryingOnce(fn (): PuSettlementResult => DB::transaction(function () use ($known, $reason, $actor, $payload): PuSettlementResult {
            [$obligation, $settlement] = $this->lockSettlement($known);

            if (! $settlement->isActive()) {
                return $this->reject($obligation->emission, 'Só a liquidação vigente pode ser estornada; esta já foi corrigida ou estornada.', $payload, $actor);
            }

            $settlement->forceFill(['status' => PuSettlementStatus::Reversed, 'superseded_at' => now()])->save();
            $reversal = EmissionPuSettlement::query()->create([
                'emission_id' => $settlement->emission_id,
                'obligation_id' => $settlement->obligation_id,
                'entry_type' => PuSettlementEntryType::Reversal,
                'status' => PuSettlementStatus::Reversal,
                'predecessor_id' => $settlement->id,
                'currency' => $settlement->currency,
                'source' => $settlement->source,
                'reason' => trim($reason),
                'recorded_by' => $actor->userId(),
                'recorded_via' => $actor->via(),
                'recorded_at' => now(),
            ]);
            $settlement->forceFill(['superseded_by_settlement_id' => $reversal->id])->save();

            $this->obligations->reconcile($obligation, 'settlement_reversed');
            $this->auditLog->logSettlementReversed($settlement, $reversal);

            return new PuSettlementResult(PuSettlementOutcome::Recorded, $reversal, obligation: $obligation->fresh());
        }));
    }

    /**
     * Decide um conflito de liquidação. Aceitar transforma os dados que chegaram
     * numa correção da liquidação vigente (ou na liquidação da obrigação, se não
     * houver vigente); rejeitar os descarta. Nos dois casos fica quem decidiu,
     * quando e por quê.
     */
    public function resolveConflict(int $conflictId, bool $accept, string $reason, PuSettlementActor $actor): PuSettlementResult
    {
        $this->authorize($actor, AccessPermission::PuSettlementCorrect);
        $known = EmissionPuSettlementConflict::query()->find($conflictId);
        $payload = ['conflict_id' => $conflictId, 'accept' => $accept, 'reason' => $reason];

        if (blank($reason) || ! $known instanceof EmissionPuSettlementConflict) {
            return $this->reject(
                $known !== null ? Emission::query()->find($known->emission_id) : null,
                blank($reason) ? 'A decisão do conflito registra o motivo.' : 'O conflito de liquidação não existe.',
                $payload,
                $actor,
            );
        }

        return $this->retryingOnce(fn (): PuSettlementResult => DB::transaction(function () use ($known, $accept, $reason, $actor, $payload): PuSettlementResult {
            Emission::query()->whereKey($known->emission_id)->lockForUpdate()->firstOrFail();
            $obligation = EmissionPuObligation::query()->whereKey($known->obligation_id)->lockForUpdate()->firstOrFail();
            $conflict = EmissionPuSettlementConflict::query()->whereKey($known->id)->lockForUpdate()->firstOrFail();

            if ($conflict->status !== PuSettlementConflictStatus::Open) {
                return $this->reject($obligation->emission, 'Este conflito de liquidação já foi decidido.', $payload, $actor);
            }

            $settlement = null;

            if ($accept) {
                $incoming = $conflict->incoming_payload;
                $amount = $this->money((string) $incoming['amount']);
                $components = $this->components(is_array($incoming['components'] ?? null) ? $incoming['components'] : null);
                $currency = (string) ($incoming['currency'] ?? 'BRL');
                $date = (string) $incoming['settlement_date'];
                $fingerprint = $this->fingerprint($obligation->id, $date, $amount, $currency, $components);
                $active = EmissionPuSettlement::query()
                    ->where('obligation_id', $obligation->id)
                    ->where('status', PuSettlementStatus::Active->value)
                    ->lockForUpdate()
                    ->first();

                $settlement = $active instanceof EmissionPuSettlement
                    ? $this->appendCorrection($active, $obligation, $date, $amount, $currency, $components, $conflict->external_reference, $fingerprint, trim($reason), $actor)
                    : EmissionPuSettlement::query()->create([
                        'emission_id' => $obligation->emission_id,
                        'obligation_id' => $obligation->id,
                        'entry_type' => PuSettlementEntryType::Settlement,
                        'status' => PuSettlementStatus::Active,
                        'settlement_date' => $date,
                        'amount' => $amount,
                        'currency' => $currency,
                        'components' => $components,
                        'source' => $conflict->source,
                        'external_reference' => $conflict->external_reference,
                        'payload_fingerprint' => $fingerprint,
                        'reason' => trim($reason),
                        'expected_calculation_id' => $obligation->current_calculation_id,
                        'recorded_by' => $actor->userId(),
                        'recorded_via' => $actor->via(),
                        'recorded_at' => now(),
                    ]);
            }

            $conflict->forceFill([
                'status' => $accept ? PuSettlementConflictStatus::Accepted : PuSettlementConflictStatus::Rejected,
                'resolved_at' => now(),
                'resolved_by' => $actor->userId(),
                'resolution_reason' => trim($reason),
                'resolution_settlement_id' => $settlement?->id,
            ])->save();

            $this->obligations->reconcile($obligation, 'settlement_conflict_resolved');
            $this->auditLog->logSettlementConflictResolved($conflict);

            return new PuSettlementResult(PuSettlementOutcome::Recorded, $settlement, $conflict, $obligation->fresh());
        }));
    }

    /**
     * @param  array<string, string>|null  $components
     */
    private function appendCorrection(
        EmissionPuSettlement $settlement,
        EmissionPuObligation $obligation,
        string $date,
        string $amount,
        string $currency,
        ?array $components,
        ?string $reference,
        string $fingerprint,
        string $reason,
        PuSettlementActor $actor,
    ): EmissionPuSettlement {
        $settlement->forceFill(['status' => PuSettlementStatus::Corrected, 'superseded_at' => now()])->save();
        $correction = EmissionPuSettlement::query()->create([
            'emission_id' => $settlement->emission_id,
            'obligation_id' => $settlement->obligation_id,
            'entry_type' => PuSettlementEntryType::Correction,
            'status' => PuSettlementStatus::Active,
            'predecessor_id' => $settlement->id,
            'settlement_date' => $date,
            'amount' => $amount,
            'currency' => $currency,
            'components' => $components,
            'source' => $settlement->source,
            'external_reference' => $reference,
            'payload_fingerprint' => $fingerprint,
            'reason' => $reason,
            'expected_calculation_id' => $obligation->current_calculation_id,
            'recorded_by' => $actor->userId(),
            'recorded_via' => $actor->via(),
            'recorded_at' => now(),
        ]);
        $settlement->forceFill(['superseded_by_settlement_id' => $correction->id])->save();

        return $correction;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function conflict(
        EmissionPuObligation $obligation,
        PuSettlementConflictKind $kind,
        ?EmissionPuSettlement $existing,
        PuSettlementData $data,
        array $payload,
        string $fingerprint,
        PuSettlementActor $actor,
    ): PuSettlementResult {
        $open = EmissionPuSettlementConflict::query()
            ->where('obligation_id', $obligation->id)
            ->where('kind', $kind->value)
            ->where('payload_fingerprint', $fingerprint)
            ->where('existing_settlement_id', $existing?->id)
            ->open()
            ->first();

        if ($open instanceof EmissionPuSettlementConflict) {
            return new PuSettlementResult(PuSettlementOutcome::Conflict, $existing, $open, $obligation, $kind->label());
        }

        $conflict = EmissionPuSettlementConflict::query()->create([
            'emission_id' => $obligation->emission_id,
            'obligation_id' => $obligation->id,
            'existing_settlement_id' => $existing?->id,
            'kind' => $kind,
            'status' => PuSettlementConflictStatus::Open,
            'source' => $data->source,
            'external_reference' => $this->reference($data->externalReference),
            'incoming_payload' => [
                'settlement_date' => $data->settlementDate,
                'amount' => $this->money($data->amount),
                'currency' => $data->currency,
                'components' => $this->components($data->components),
                'request' => $payload,
            ],
            'payload_fingerprint' => $fingerprint,
            'detected_at' => now(),
            'detected_by' => $actor->userId(),
            'detected_via' => $actor->via(),
        ]);

        $this->obligations->reconcile($obligation, 'settlement_conflict_detected');
        $this->auditLog->logSettlementConflictDetected($conflict);

        return new PuSettlementResult(PuSettlementOutcome::Conflict, $existing, $conflict, $obligation->fresh(), $kind->label());
    }

    /**
     * @return array{0: EmissionPuObligation, 1: EmissionPuSettlement}
     */
    private function lockSettlement(EmissionPuSettlement $known): array
    {
        Emission::query()->whereKey($known->emission_id)->lockForUpdate()->firstOrFail();
        $obligation = EmissionPuObligation::query()->whereKey($known->obligation_id)->lockForUpdate()->firstOrFail();
        $settlement = EmissionPuSettlement::query()->whereKey($known->id)->lockForUpdate()->firstOrFail();

        return [$obligation, $settlement];
    }

    private function locateObligation(Emission $emission, PuSettlementData $data): ?EmissionPuObligation
    {
        $query = EmissionPuObligation::query()->where('emission_id', $emission->id);

        if ($data->obligationId !== null) {
            return $query->whereKey($data->obligationId)->lockForUpdate()->first();
        }

        return $query
            ->where('obligation_type', $data->obligationType?->value)
            ->whereDate('contractual_date', (string) $data->contractualDate)
            ->where('sequence', $data->sequence)
            ->lockForUpdate()
            ->first();
    }

    /**
     * O lançamento que hoje responde pela linhagem de uma liquidação: segue as
     * correções até a vigente (ou até o estorno).
     */
    private function lineageHead(EmissionPuSettlement $settlement): EmissionPuSettlement
    {
        $head = $settlement;
        $guard = 0;

        while ($head->superseded_by_settlement_id !== null && $guard++ < 100) {
            $next = EmissionPuSettlement::query()->whereKey($head->superseded_by_settlement_id)->lockForUpdate()->first();

            if (! $next instanceof EmissionPuSettlement) {
                break;
            }

            $head = $next;
        }

        return $head;
    }

    /**
     * @param  array<string, string>|null  $components
     */
    private function invalid(string $date, string $amount, ?array $components, string $currency): ?string
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return 'A data da liquidação precisa ser uma data válida (AAAA-MM-DD).';
        }

        if (preg_match(self::MONEY_PATTERN, $amount) !== 1) {
            return 'O valor liquidado precisa ser um valor monetário não negativo com até duas casas decimais.';
        }

        if ($currency !== 'BRL') {
            return 'Só liquidação em reais (BRL) é suportada.';
        }

        if ($components === null) {
            return null;
        }

        if ($components === []) {
            return 'Componentes informados vazios: informe os componentes da origem ou só o total.';
        }

        $sum = '0.00';

        foreach ($components as $component => $value) {
            if (! PuObligationComponent::tryFrom((string) $component) instanceof PuObligationComponent) {
                return sprintf('Componente de liquidação desconhecido: %s.', (string) $component);
            }

            if (! is_string($value) || preg_match(self::MONEY_PATTERN, $value) !== 1) {
                return sprintf('O valor do componente %s precisa ser monetário, não negativo, com até duas casas.', (string) $component);
            }

            $sum = bcadd($sum, $value, 2);
        }

        if (bccomp($sum, $this->money($amount), 2) !== 0) {
            return sprintf('Os componentes informados somam %s, mas o valor liquidado é %s.', $sum, $this->money($amount));
        }

        return null;
    }

    /**
     * @param  array<string, string>|null  $components
     * @return array<string, string>|null
     */
    private function components(?array $components): ?array
    {
        if ($components === null || $components === []) {
            return null;
        }

        $normalized = [];

        foreach ($components as $component => $value) {
            $normalized[(string) $component] = $this->money((string) $value);
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, string>|null  $components
     */
    private function fingerprint(int $obligationId, string $date, string $amount, string $currency, ?array $components): string
    {
        return hash('sha256', (string) json_encode([
            'obligation_id' => $obligationId,
            'settlement_date' => $date,
            'amount' => $amount,
            'currency' => $currency,
            'components' => $components,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }

    private function reference(?string $reference): ?string
    {
        $reference = $reference !== null ? trim($reference) : '';

        return $reference === '' ? null : $reference;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PuSettlementData $data): array
    {
        return [
            'emission_id' => $data->emissionId,
            'obligation_id' => $data->obligationId,
            'obligation_type' => $data->obligationType?->value,
            'contractual_date' => $data->contractualDate,
            'sequence' => $data->sequence,
            'settlement_date' => $data->settlementDate,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'components' => $data->components,
            'source' => $data->source->value,
            'external_reference' => $data->externalReference,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reject(?Emission $emission, string $reason, array $payload, PuSettlementActor $actor): PuSettlementResult
    {
        $this->auditLog->logSettlementRejected($emission, $reason, $payload, $actor->via(), $actor->userId());

        return new PuSettlementResult(PuSettlementOutcome::Rejected, reason: $reason);
    }

    private function authorize(PuSettlementActor $actor, AccessPermission $permission): void
    {
        if ($actor->user !== null && ! $actor->user->can($permission->value)) {
            throw new AuthorizationException(sprintf('Sem permissão para esta operação de liquidação (%s).', $permission->label()));
        }
    }

    /**
     * @param  callable(): PuSettlementResult  $operation
     */
    private function retryingOnce(callable $operation): PuSettlementResult
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException) {
            return $operation();
        }
    }
}
