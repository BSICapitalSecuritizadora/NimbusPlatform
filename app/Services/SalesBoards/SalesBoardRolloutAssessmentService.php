<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\DTOs\SalesBoards\SalesBoardSourceObservation;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRolloutComparisonStatus;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Support\SalesBoards\CanonicalDigest;
use App\Support\SalesBoards\RolloutPosition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O que a Gestão precisa olhar antes de automatizar uma Emissão.
 *
 * Somente calcula. Não muda modo, não ativa, não cria ciclo, não escreve em
 * `sales_boards`. Homologar é ato de leitura sobre a derivação -- gerar um ciclo
 * "só para conferir" congelaria uma posição que ninguém pediu e que a Fase F
 * depois encontraria como já existente.
 *
 * A comparação é sempre entre o que o consumidor **hoje** enxerga e o que o
 * motor novo produziria. Por isso a posição legada vem do
 * {@see SalesBoardPositionReader}, com carry-forward, e não de uma consulta
 * direta a `sales_boards`: é o leitor que Garantias e Relatório usam, e comparar
 * contra outra coisa homologaria um número que ninguém consome.
 */
class SalesBoardRolloutAssessmentService
{
    public function __construct(
        private readonly SalesBoardDerivationService $derivationService,
        private readonly SalesBoardReadinessService $readinessService,
        private readonly SalesBoardFingerprintService $fingerprintService,
        private readonly SalesBoardPositionReader $positionReader,
    ) {}

    /**
     * Reavalia a homologação e regrava o retrato dos empreendimentos.
     *
     * Idempotente: reavaliar duas vezes sobre a mesma fonte produz o mesmo
     * `assessment_hash` e preserva os aceites. O que invalida um aceite é a
     * fonte ter mudado -- e é o fingerprint que responde isso, não o relógio.
     */
    public function assess(SalesBoardRolloutHomologation $homologation): SalesBoardRolloutHomologation
    {
        $emission = $homologation->emission()->firstOrFail();
        $constructions = $this->constructionsOf($emission);

        $comparisonMonth = CarbonImmutable::parse(
            $homologation->comparison_reference_month->toDateString()
        )->startOfMonth();
        $startMonth = $homologation->startsAt();

        /**
         * Derivação e observação da fonte numa transação só, pelo mesmo motivo
         * da geração: comparar um snapshot de um instante com um resumo de fonte
         * de outro acusaria diferenças que nunca existiram ao mesmo tempo.
         */
        [$positions, $observations] = DB::transaction(fn (): array => [
            $this->derivationService->deriveForConstructions($constructions, $comparisonMonth),
            $this->fingerprintService->observeForConstructions($constructions, $comparisonMonth),
        ]);

        $legacyBoards = $this->legacyBoardsAtOrAfter($constructions, $startMonth);
        $cycles = $this->cyclesAtOrAfter($constructions, $startMonth);

        foreach ($constructions as $constructionId => $construction) {
            $this->writeConstructionAssessment(
                homologation: $homologation,
                construction: $construction,
                position: $positions[$constructionId],
                observation: $observations[$constructionId] ?? null,
                comparisonMonth: $comparisonMonth,
                legacyBoards: $legacyBoards->get($constructionId, collect()),
                cycles: $cycles->get($constructionId, collect()),
            );
        }

        /**
         * Linhas de empreendimentos que saíram da Emissão desde a última
         * avaliação não podem continuar contando como revisadas. Elas são
         * apagadas -- e não marcadas -- porque a homologação é rascunho: o que
         * ela afirma é o retrato atual, e o histórico das tentativas anteriores
         * continua nas outras attempts.
         */
        $homologation->constructions()
            ->whereNotIn('construction_id', $constructions->keys()->all())
            ->delete();

        $homologation->forceFill([
            'construction_scope_hash' => $this->scopeHash($constructions->keys()->all()),
            'assessment_hash' => $this->assessmentHash($homologation->fresh()),
            'assessed_at' => CarbonImmutable::now(),
        ])->save();

        return $homologation->refresh();
    }

    private function writeConstructionAssessment(
        SalesBoardRolloutHomologation $homologation,
        Construction $construction,
        SalesBoardDerivedPosition $position,
        ?SalesBoardSourceObservation $observation,
        CarbonImmutable $comparisonMonth,
        Collection $legacyBoards,
        Collection $cycles,
    ): void {
        $readiness = $this->readinessService->fromPosition($construction, $position);

        $derived = RolloutPosition::fromDerived($position);
        $legacyPosition = $this->positionReader->forConstruction($construction, $comparisonMonth);
        $legacy = RolloutPosition::fromLegacy($legacyPosition);

        $delta = RolloutPosition::delta($legacy, $derived);

        $status = match (true) {
            $legacy === null => SalesBoardRolloutComparisonStatus::NoLegacyPosition,
            $delta['has_difference'] => SalesBoardRolloutComparisonStatus::Different,
            default => SalesBoardRolloutComparisonStatus::Matched,
        };

        $existing = $homologation->constructions()
            ->where('construction_id', $construction->getKey())
            ->first();

        $snapshotFingerprint = $this->snapshotFingerprint($derived);
        $sourceFingerprint = $observation?->fingerprint();

        /**
         * O aceite de uma diferença vale para a diferença que foi vista. Se a
         * fonte, a posição derivada, a legada ou o veredito mudaram, a Gestão
         * precisa olhar de novo -- manter o aceite deixaria uma diferença nova
         * aprovada por alguém que nunca a viu.
         */
        $keepsAcceptance = $existing !== null
            && $existing->accepted_difference
            && $existing->source_fingerprint === $sourceFingerprint
            && $existing->snapshot_fingerprint === $snapshotFingerprint
            && $existing->comparison_status === $status
            && $existing->legacy_position == $legacy;

        $attributes = [
            'is_ready' => $readiness->isReady(),
            'blocker_codes' => array_keys($readiness->blockingIssueCounts()),
            'blocker_message' => $readiness->isReady()
                ? null
                : collect($readiness->blockingIssueCounts())
                    ->map(fn (int $count, string $code): string => sprintf('%s (%d)', $code, $count))
                    ->implode('; '),
            'source_fingerprint' => $sourceFingerprint,
            'snapshot_fingerprint' => $snapshotFingerprint,
            'comparison_status' => $status,
            'legacy_position' => $legacy,
            'derived_position' => $derived,
            'position_delta' => $delta,
            'legacy_sales_board_id' => $legacyPosition->salesBoard?->getKey(),
            'legacy_reference_month' => $legacyPosition->referenceMonthUsedDate(),
            'has_cycle_at_or_after_start' => $cycles->isNotEmpty(),
            'has_cancelled_cycle_at_or_after_start' => $cycles
                ->contains(fn (SalesBoardCycle $cycle): bool => $cycle->status === SalesBoardCycleStatus::Cancelled),
            'latest_legacy_board_month' => $legacyBoards
                ->max(fn (SalesBoard $board): string => $board->reference_month->toDateString()),
        ];

        if (! $keepsAcceptance) {
            $attributes += [
                'accepted_difference' => false,
                'difference_reason' => null,
                'accepted_at' => null,
                'accepted_by_user_id' => null,
            ];
        }

        if ($existing === null) {
            $homologation->constructions()->create([
                'construction_id' => $construction->getKey(),
                ...$attributes,
            ]);

            return;
        }

        $existing->forceFill($attributes)->save();
    }

    /**
     * Quadros manuais existentes a partir da competência, **sem gravar nada**.
     *
     * A ativação precisa reconferir o conflito contra o banco de agora -- um
     * quadro manual criado depois da aprovação é exatamente o caso -- e não pode
     * fazer isso reavaliando a homologação: ela já está aprovada, e portanto
     * imutável. Reavaliar ali tentaria reescrever o retrato revisado e esbarraria
     * no guard, transformando uma recusa de domínio prevista num erro de
     * programação.
     *
     * @return list<string> descrição por empreendimento em conflito
     */
    public function legacyConflictsFor(Emission $emission, CarbonImmutable $startMonth): array
    {
        $constructions = $this->constructionsOf($emission);

        if ($constructions->isEmpty()) {
            return [];
        }

        return $this->legacyBoardsAtOrAfter($constructions, $startMonth)
            ->map(function (Collection $boards, int $constructionId) use ($constructions): string {
                $latest = $boards->max(fn (SalesBoard $board): string => $board->reference_month->toDateString());

                return sprintf(
                    '%s (até %s)',
                    (string) ($constructions->get($constructionId)?->development_name ?? '#'.$constructionId),
                    CarbonImmutable::parse($latest)->format('m/Y'),
                );
            })
            ->values()
            ->all();
    }

    /**
     * Os empreendimentos da Emissão, agora.
     *
     * @return Collection<int, Construction>
     */
    public function constructionsOf(Emission $emission): Collection
    {
        return Construction::query()
            ->where('emission_id', $emission->getKey())
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Construction $construction): int => (int) $construction->getKey());
    }

    /**
     * O resumo determinístico do conjunto de empreendimentos.
     *
     * É o que detecta escopo alterado depois da ativação: um empreendimento
     * novo muda o hash, e a Emissão inteira sai da elegibilidade até alguém
     * homologar de novo.
     *
     * @param  list<int>  $constructionIds
     */
    public function scopeHash(array $constructionIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $constructionIds)));
        sort($ids);

        return CanonicalDigest::of([
            'construction_scope' => array_map(
                fn (int $id): string => CanonicalDigest::row([$id]),
                $ids,
            ),
        ]);
    }

    /**
     * O resumo dos fatos materiais que a homologação revisou.
     *
     * Sem timestamp, de propósito: uma chave que muda sozinha com o relógio
     * invalidaria toda homologação a cada segundo e não detectaria mudança
     * nenhuma. O que entra é o que, se mudar, obriga a Gestão a olhar de novo.
     */
    public function assessmentHash(SalesBoardRolloutHomologation $homologation): string
    {
        $rows = $homologation->constructions()
            ->orderBy('construction_id')
            ->get()
            ->map(fn (SalesBoardRolloutHomologationConstruction $row): string => CanonicalDigest::row([
                (int) $row->construction_id,
                $row->is_ready,
                implode(',', $row->blockerCodes()),
                $row->source_fingerprint,
                $row->snapshot_fingerprint,
                $row->comparison_status->value,
                self::flattenPosition($row->legacy_position),
                self::flattenPosition($row->derived_position),
                $row->legacy_sales_board_id === null ? null : (int) $row->legacy_sales_board_id,
            ]))
            ->all();

        return CanonicalDigest::of([
            'homologation' => [CanonicalDigest::row([
                (int) $homologation->emission_id,
                $homologation->proposed_start_reference_month->format('Y-m'),
                $homologation->comparison_reference_month->format('Y-m'),
            ])],
            'constructions' => $rows,
        ]);
    }

    /**
     * O resumo da posição derivada, usado para detectar mudança material.
     *
     * @param  array<string, mixed>  $derived
     */
    private function snapshotFingerprint(array $derived): string
    {
        return CanonicalDigest::of([
            'derived_position' => [CanonicalDigest::row([self::flattenPosition($derived)])],
        ]);
    }

    /**
     * Uma posição canônica reduzida a um texto estável.
     *
     * O digest recusa estruturas aninhadas -- e com razão: a ordem das chaves de
     * um array não é garantia de nada. Achatar aqui, numa ordem fixa de baldes,
     * é o que torna o mesmo conjunto de números sempre o mesmo resumo.
     *
     * `null` num balde vira o marcador de ausência do próprio digest, e não
     * zero: "não foi possível saber" e "vale zero" são fatos diferentes e não
     * podem colidir.
     *
     * @param  array<string, mixed>|null  $position
     */
    private static function flattenPosition(?array $position): ?string
    {
        if ($position === null) {
            return null;
        }

        $parts = [
            (string) ($position['version'] ?? ''),
            (string) ($position['reference_month'] ?? ''),
            (string) ($position['total_units'] ?? ''),
        ];

        foreach (RolloutPosition::BUCKETS as $bucket) {
            $parts[] = $bucket;
            $parts[] = (string) ($position['buckets'][$bucket]['units'] ?? '');
            $parts[] = $position['buckets'][$bucket]['value_cents'] === null
                ? CanonicalDigest::NULL_MARKER
                : (string) $position['buckets'][$bucket]['value_cents'];
        }

        return implode('/', $parts);
    }

    /**
     * Quadros legados existentes a partir da competência de ativação.
     *
     * Uma consulta para o lote inteiro. É o conflito mais importante da fase:
     * se já existe quadro manual em 09/2026 e a ativação começa em 08/2026, a
     * publicação de setembro será recusada pela Fase E -- e o rollout nasceria
     * quebrado.
     *
     * @param  Collection<int, Construction>  $constructions
     * @return Collection<int, Collection<int, SalesBoard>>
     */
    private function legacyBoardsAtOrAfter(Collection $constructions, CarbonImmutable $startMonth): Collection
    {
        return SalesBoard::query()
            ->whereIn('construction_id', $constructions->keys()->all())
            ->where('reference_month', '>=', $startMonth->toDateString())
            ->get()
            ->groupBy(fn (SalesBoard $board): int => (int) $board->construction_id);
    }

    /**
     * @param  Collection<int, Construction>  $constructions
     * @return Collection<int, Collection<int, SalesBoardCycle>>
     */
    private function cyclesAtOrAfter(Collection $constructions, CarbonImmutable $startMonth): Collection
    {
        return SalesBoardCycle::query()
            ->whereIn('construction_id', $constructions->keys()->all())
            ->where('reference_month', '>=', $startMonth->toDateString())
            ->get()
            ->groupBy(fn (SalesBoardCycle $cycle): int => (int) $cycle->construction_id);
    }
}
