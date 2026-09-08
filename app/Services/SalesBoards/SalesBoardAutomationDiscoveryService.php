<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardAutomationEligibleTarget;
use App\DTOs\SalesBoards\SalesBoardAutomationTargetCandidate;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\Construction;
use App\Models\SalesBoardAutomationTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Quais competências estão devidas e ainda pedem alguma coisa.
 *
 * Descobrir e materializar são passos separados, e a separação é o que faz o
 * `--dry-run` ser honesto: {@see self::discover()} não escreve, então a prévia
 * mostra exatamente o que aconteceria sem deixar rastro para depois limpar.
 *
 * A descoberta cruza três coisas: quem está habilitado (o provider), desde
 * quando (a competência de ativação) e o que já venceu (o dia 13). Nenhuma
 * delas é adivinhada aqui.
 */
class SalesBoardAutomationDiscoveryService
{
    public function __construct(
        private readonly SalesBoardAutomationEligibilityProvider $eligibilityProvider,
        private readonly SalesBoardAutomationDueDateService $dueDates,
    ) {}

    /**
     * As competências devidas na data indicada, sem escrever nada.
     *
     * @return list<SalesBoardAutomationTargetCandidate>
     */
    public function discover(CarbonImmutable $businessDate): array
    {
        $candidates = [];

        foreach ($this->eligibilityProvider->eligibleTargets() as $eligible) {
            foreach ($this->dueMonthsFor($eligible, $businessDate) as $month) {
                $candidates[] = new SalesBoardAutomationTargetCandidate(
                    constructionId: $eligible->constructionId,
                    referenceMonth: $month,
                    dueDate: $this->dueDates->dueDateFor($month),
                    autoOpenBuilderReview: $eligible->autoOpenBuilderReview,
                );
            }
        }

        return $this->withKnownConstructionsOnly($candidates);
    }

    /**
     * Cria as linhas de estado que ainda não existem e devolve os alvos abertos.
     *
     * `firstOrCreate` mais captura da unique: duas instâncias que descubram a
     * mesma competência no mesmo segundo disputam a chave, e a perdedora relê a
     * linha da vencedora em vez de propagar o erro do banco. É o mesmo padrão
     * que a geração da Fase C usa, e pela mesma razão -- a corrida é esperada,
     * não excepcional.
     *
     * Alvos já satisfeitos não voltam: a competência deles terminou, mesmo que o
     * ciclo hoje esteja em validação, análise ou aprovado.
     *
     * @param  list<SalesBoardAutomationTargetCandidate>  $candidates
     * @return list<SalesBoardAutomationTarget>
     */
    public function materialize(array $candidates): array
    {
        $targets = [];

        foreach ($candidates as $candidate) {
            $target = $this->materializeOne($candidate);

            if ($target->status !== SalesBoardAutomationTargetStatus::Satisfied) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    private function materializeOne(SalesBoardAutomationTargetCandidate $candidate): SalesBoardAutomationTarget
    {
        $attributes = [
            'construction_id' => $candidate->constructionId,
            'reference_month' => $candidate->referenceMonth->toDateString(),
        ];

        try {
            return SalesBoardAutomationTarget::query()->create([
                ...$attributes,
                'due_date' => $candidate->dueDate->toDateString(),
                'status' => SalesBoardAutomationTargetStatus::Pending,
                'attempt_count' => 0,
                'auto_open_builder_review' => $candidate->autoOpenBuilderReview,
            ]);
        } catch (UniqueConstraintViolationException) {
            /**
             * Outra instância chegou primeiro. A linha dela é a boa.
             */
            return SalesBoardAutomationTarget::query()
                ->where('construction_id', $candidate->constructionId)
                ->whereDate('reference_month', $candidate->referenceMonth->toDateString())
                ->firstOrFail();
        }
    }

    /**
     * As competências devidas de um habilitado, já filtradas pelo que ele
     * declarou como início.
     *
     * @return list<CarbonImmutable>
     */
    private function dueMonthsFor(
        SalesBoardAutomationEligibleTarget $eligible,
        CarbonImmutable $businessDate,
    ): array {
        return $this->dueDates->dueReferenceMonths($eligible->startReferenceMonth, $businessDate);
    }

    /**
     * Descarta candidatos cujo empreendimento não existe mais.
     *
     * Uma consulta só para o lote inteiro: verificar um por um transformaria a
     * descoberta em N consultas antes de qualquer derivação, e a Fase B já
     * pagou o preço de aprender isso.
     *
     * @param  list<SalesBoardAutomationTargetCandidate>  $candidates
     * @return list<SalesBoardAutomationTargetCandidate>
     */
    private function withKnownConstructionsOnly(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(
            fn (SalesBoardAutomationTargetCandidate $candidate): int => $candidate->constructionId,
            $candidates,
        )));

        $known = Construction::query()->whereKey($ids)->pluck('id')->all();
        $known = array_flip(array_map('intval', $known));

        return array_values(array_filter(
            $candidates,
            fn (SalesBoardAutomationTargetCandidate $candidate): bool => isset($known[$candidate->constructionId]),
        ));
    }

    /**
     * Os alvos abertos que já podem tentar de novo.
     *
     * Separado da materialização porque um alvo recém-criado e um alvo bloqueado
     * há três dias são a mesma pergunta para o orquestrador -- "quem pode tentar
     * agora?" -- e responder isso no banco evita carregar a carteira inteira.
     *
     * @return list<SalesBoardAutomationTarget>
     */
    public function attemptable(array $targetIds, CarbonImmutable $now): array
    {
        if ($targetIds === []) {
            return [];
        }

        return SalesBoardAutomationTarget::query()
            ->whereKey($targetIds)
            ->whereNot('status', SalesBoardAutomationTargetStatus::Satisfied)
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $now);
            })
            ->orderBy('reference_month')
            ->orderBy('construction_id')
            ->get()
            ->all();
    }

    /**
     * Quantos alvos ficaram de fora por ainda estarem em espera de retry.
     */
    public function countWaiting(array $targetIds, CarbonImmutable $now): int
    {
        if ($targetIds === []) {
            return 0;
        }

        return DB::table('sales_board_automation_targets')
            ->whereIn('id', $targetIds)
            ->where('status', '!=', SalesBoardAutomationTargetStatus::Satisfied->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '>', $now)
            ->count();
    }
}
