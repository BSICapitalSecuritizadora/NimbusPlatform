<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardAutomationEligibleTarget;
use App\DTOs\SalesBoards\SalesBoardAutomationTargetCandidate;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\Construction;
use App\Models\SalesBoardAutomationTarget;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Quem está habilitado agora, segundo o provider.
     *
     * Exposto para o orquestrador perguntar uma vez só por execução e usar a
     * mesma resposta na descoberta, no encerramento de alvos órfãos e no
     * recorte dos lembretes.
     *
     * @return list<SalesBoardAutomationEligibleTarget>
     */
    public function eligible(): array
    {
        return $this->eligibilityProvider->eligibleTargets();
    }

    /**
     * As competências devidas na data indicada, sem escrever nada.
     *
     * @param  list<SalesBoardAutomationEligibleTarget>|null  $eligibleTargets  a resposta do provider, se já lida
     * @return list<SalesBoardAutomationTargetCandidate>
     */
    public function discover(CarbonImmutable $businessDate, ?array $eligibleTargets = null): array
    {
        $candidates = [];

        foreach ($eligibleTargets ?? $this->eligible() as $eligible) {
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
     * Os alvos já existentes do lote são lidos numa consulta só, e só os que
     * faltam são inseridos. Antes cada execução horária tentava o `INSERT` de
     * todas as competências desde a ativação -- satisfeitas inclusive --, pagava
     * uma violação de unique, uma exceção e uma releitura por competência, e
     * crescia para sempre com a idade da carteira.
     *
     * A captura da unique continua: duas instâncias que descubram a mesma
     * competência no mesmo segundo disputam a chave, e a perdedora relê a linha
     * da vencedora em vez de propagar o erro do banco. É o mesmo padrão que a
     * geração da Fase C usa, e pela mesma razão -- a corrida é esperada, não
     * excepcional.
     *
     * Alvos já satisfeitos não voltam: a competência deles terminou, mesmo que o
     * ciclo hoje esteja em validação, análise ou aprovado. Alvos encerrados
     * voltam, reabertos: se a competência está de novo no perímetro (uma nova
     * ativação que a cobre), ela voltou a ser responsabilidade da automação, e a
     * unique impediria qualquer outra linha de assumir o lugar. A exceção é a
     * competência cancelada pela Gestão: ela não saiu do perímetro, foi
     * encerrada, e reabri-la desfaria a decisão.
     *
     * @param  list<SalesBoardAutomationTargetCandidate>  $candidates
     * @return list<SalesBoardAutomationTarget>
     */
    public function materialize(array $candidates): array
    {
        $existing = $this->existingFor($candidates);
        $targets = [];

        foreach ($candidates as $candidate) {
            $target = $existing->get($candidate->key()) ?? $this->materializeOne($candidate);

            if (($target->status === SalesBoardAutomationTargetStatus::Closed)
                && ($target->closure_reason?->isReopenable() ?? true)) {
                $target = $this->reopen($target);
            }

            if ($target->status->isOpen()) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Os candidatos que ainda pedem alguma coisa, sem escrever nada.
     *
     * É o que a prévia mostra: uma competência já satisfeita não "aconteceria"
     * de novo, e listá-la faria a prévia de uma carteira de três anos parecer
     * um catch-up inteiro.
     *
     * @param  list<SalesBoardAutomationTargetCandidate>  $candidates
     * @return list<SalesBoardAutomationTargetCandidate>
     */
    public function unsettled(array $candidates): array
    {
        $existing = $this->existingFor($candidates);

        return array_values(array_filter(
            $candidates,
            fn (SalesBoardAutomationTargetCandidate $candidate): bool => ! $this->isSettled($existing->get($candidate->key())),
        ));
    }

    /**
     * O alvo já terminou para a automação: satisfeito, ou encerrado por uma
     * competência cancelada -- que a descoberta não reabre.
     */
    private function isSettled(?SalesBoardAutomationTarget $target): bool
    {
        return match ($target?->status) {
            SalesBoardAutomationTargetStatus::Satisfied => true,
            SalesBoardAutomationTargetStatus::Closed => ! ($target->closure_reason?->isReopenable() ?? true),
            default => false,
        };
    }

    /**
     * Os alvos que já existem para os candidatos, indexados pela chave do
     * candidato.
     *
     * Uma consulta para o lote inteiro, delimitada pelos empreendimentos e pelo
     * intervalo de competências do próprio lote.
     *
     * @param  list<SalesBoardAutomationTargetCandidate>  $candidates
     * @return Collection<string, SalesBoardAutomationTarget>
     */
    private function existingFor(array $candidates): Collection
    {
        if ($candidates === []) {
            return collect();
        }

        $months = array_map(fn (SalesBoardAutomationTargetCandidate $candidate): CarbonImmutable => $candidate->referenceMonth, $candidates);
        $constructionIds = array_values(array_unique(array_map(
            fn (SalesBoardAutomationTargetCandidate $candidate): int => $candidate->constructionId,
            $candidates,
        )));

        return SalesBoardAutomationTarget::query()
            ->whereIn('construction_id', $constructionIds)
            ->where('reference_month', '>=', min($months)->startOfMonth()->toDateString())
            ->where('reference_month', '<=', InclusiveDateBound::upperBound(max($months)->endOfMonth()))
            ->get()
            ->keyBy(fn (SalesBoardAutomationTarget $target): string => $target->construction_id.'@'.$target->reference_month->format('Y-m'));
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
     * Devolve à automação um alvo encerrado cuja competência voltou ao
     * perímetro.
     *
     * Condicional ao estado: se outra instância já reabriu, a atualização não
     * encontra a linha encerrada e a leitura seguinte devolve o estado dela. O
     * histórico de tentativas continua inteiro, e o registro do último
     * encerramento fica na linha para quem quiser saber por que ela esteve
     * parada.
     */
    private function reopen(SalesBoardAutomationTarget $target): SalesBoardAutomationTarget
    {
        $reopened = SalesBoardAutomationTarget::query()
            ->whereKey($target->getKey())
            ->where('status', SalesBoardAutomationTargetStatus::Closed->value)
            ->update([
                'status' => SalesBoardAutomationTargetStatus::Pending->value,
                'next_attempt_at' => null,
                'consecutive_failure_count' => 0,
            ]);

        if ($reopened === 1) {
            Log::info('Sales board automation target reopened', [
                'event' => 'sales_board_automation_target_reopened',
                'target_id' => (int) $target->getKey(),
                'construction_id' => (int) $target->construction_id,
                'reference_month' => $target->reference_month?->format('Y-m'),
            ]);
        }

        return $target->fresh() ?? $target;
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
            ->whereIn('status', SalesBoardAutomationTargetStatus::openCases())
            ->whereNull('in_flight_run_id')
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
     * Quantos alvos ficaram de fora por ainda estarem em espera de retry, ou
     * com a tentativa reservada por outra execução.
     */
    public function countWaiting(array $targetIds, CarbonImmutable $now): int
    {
        if ($targetIds === []) {
            return 0;
        }

        return DB::table('sales_board_automation_targets')
            ->whereIn('id', $targetIds)
            ->whereIn('status', array_map(
                fn (SalesBoardAutomationTargetStatus $status): string => $status->value,
                SalesBoardAutomationTargetStatus::openCases(),
            ))
            ->where(function ($query) use ($now): void {
                $query->whereNotNull('in_flight_run_id')
                    ->orWhere(function ($query) use ($now): void {
                        $query->whereNotNull('next_attempt_at')
                            ->where('next_attempt_at', '>', $now);
                    });
            })
            ->count();
    }
}
