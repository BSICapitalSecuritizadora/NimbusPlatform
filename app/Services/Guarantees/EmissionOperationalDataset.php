<?php

namespace App\Services\Guarantees;

use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundBalanceHistory;
use App\Models\Receivable;
use App\Models\SalesBoard;
use Illuminate\Support\Collection;

/**
 * Índice em memória dos dados operacionais de uma emissão.
 *
 * Existe por causa de §42: sem ele, apurar 12 competências × N garantias
 * dispararia uma consulta por combinação. Aqui as relações são carregadas uma
 * vez e reindexadas por competência, e o motor só faz lookups.
 */
class EmissionOperationalDataset
{
    /** @var Collection<int|string, Collection<int, SalesBoard>> */
    private Collection $salesBoardsByConstruction;

    /** @var Collection<string, Receivable> */
    private Collection $receivablesByMonth;

    /** @var Collection<int, Fund> */
    private Collection $funds;

    /** @var array<string, Collection<int, SalesBoard>> */
    private array $salesBoardCache = [];

    public function __construct(private readonly Emission $emission)
    {
        $emission->loadMissing([
            'salesBoards',
            'receivables',
            'funds.balanceHistories',
            'puHistories',
            'integralizationHistories',
        ]);

        $this->salesBoardsByConstruction = $emission->salesBoards
            ->filter(fn (SalesBoard $salesBoard): bool => $salesBoard->reference_month !== null)
            ->sortByDesc(fn (SalesBoard $salesBoard): string => $salesBoard->reference_month->copy()->startOfMonth()->toDateString())
            ->groupBy('construction_id');

        $this->receivablesByMonth = $emission->receivables
            ->filter(fn (Receivable $receivable): bool => $receivable->reference_month !== null)
            ->keyBy(fn (Receivable $receivable): string => $receivable->reference_month->copy()->startOfMonth()->toDateString());

        $this->funds = $emission->funds;
    }

    public function emission(): Emission
    {
        return $this->emission;
    }

    public function receivableForMonth(string $referenceMonth): ?Receivable
    {
        return $this->receivablesByMonth->get($referenceMonth);
    }

    /**
     * Quadro de vendas vigente de cada empreendimento na competência: o mais
     * recente cuja referência não ultrapasse o mês.
     *
     * Um empreendimento que parou de enviar quadro continua contando pelo
     * último enviado — é a posição conhecida, e descartá-la zeraria o estoque
     * de quem apenas atrasou o envio.
     *
     * @return Collection<int, SalesBoard>
     */
    public function salesBoardsForMonth(string $referenceMonth, ?int $constructionId = null): Collection
    {
        $cacheKey = $referenceMonth.'|'.($constructionId ?? 'all');

        if (isset($this->salesBoardCache[$cacheKey])) {
            return $this->salesBoardCache[$cacheKey];
        }

        $groups = $constructionId === null
            ? $this->salesBoardsByConstruction
            : $this->salesBoardsByConstruction->filter(
                fn (Collection $boards, int|string|null $key): bool => (int) $key === $constructionId,
            );

        return $this->salesBoardCache[$cacheKey] = $groups
            ->map(fn (Collection $salesBoards): ?SalesBoard => $salesBoards->first(
                fn (SalesBoard $salesBoard): bool => $salesBoard->reference_month->copy()->startOfMonth()->toDateString() <= $referenceMonth,
            ))
            ->filter(fn (?SalesBoard $salesBoard): bool => $salesBoard instanceof SalesBoard)
            ->values();
    }

    /**
     * @return Collection<int, Fund>
     */
    public function fundsFor(?int $fundId): Collection
    {
        if ($fundId === null) {
            return $this->funds;
        }

        return $this->funds->filter(fn (Fund $fund): bool => $fund->getKey() === $fundId)->values();
    }

    /**
     * Saldo de um fundo na competência.
     *
     * O saldo corrente do fundo só vale se tiver sido atualizado dentro do mês;
     * fora disso vale o histórico daquele mês. Sem nenhum dos dois, devolve
     * `null` — a conta não tem saldo conhecido na competência.
     */
    public function fundBalanceForMonth(Fund $fund, string $referenceMonth): ?float
    {
        if (
            ($fund->balance !== null)
            && ($fund->balance_updated_at !== null)
            && ($fund->balance_updated_at->copy()->startOfMonth()->toDateString() === $referenceMonth)
        ) {
            return round((float) $fund->balance, 2);
        }

        $history = $fund->balanceHistories
            ->first(fn (FundBalanceHistory $balanceHistory): bool => $balanceHistory->date?->copy()->startOfMonth()->toDateString() === $referenceMonth);

        if (! $history instanceof FundBalanceHistory) {
            return null;
        }

        return round((float) $history->balance, 2);
    }
}
