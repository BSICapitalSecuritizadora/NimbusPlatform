<?php

namespace App\Services\Guarantees;

use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundBalanceHistory;
use App\Models\Receivable;
use App\Models\SalesBoard;
use App\Services\SalesBoards\SalesBoardPositionReader;
use Carbon\CarbonImmutable;
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
    /** @var Collection<string, Receivable> */
    private Collection $receivablesByMonth;

    /** @var Collection<int, Fund> */
    private Collection $funds;

    /** @var array<string, Collection<int, SalesBoard>> */
    private array $salesBoardCache = [];

    private readonly SalesBoardPositionReader $salesBoardPositionReader;

    public function __construct(private readonly Emission $emission)
    {
        $emission->loadMissing([
            'constructions',
            'salesBoards',
            'receivables',
            'funds.balanceHistories',
            'puHistories',
            'integralizationHistories',
        ]);

        $this->salesBoardPositionReader = app(SalesBoardPositionReader::class);

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
     * A regra vive no {@see SalesBoardPositionReader}, que é a leitura única de
     * posição de toda a aplicação; aqui ela é apenas aplicada sobre as relações
     * já carregadas, sem consulta nova. O resultado numérico é o mesmo de antes
     * da centralização — as garantias sempre leram a posição certa, e o que
     * mudou foi só deixarem de ser as únicas a lê-la assim.
     *
     * @return Collection<int, SalesBoard>
     */
    public function salesBoardsForMonth(string $referenceMonth, ?int $constructionId = null): Collection
    {
        $cacheKey = $referenceMonth.'|'.($constructionId ?? 'all');

        if (isset($this->salesBoardCache[$cacheKey])) {
            return $this->salesBoardCache[$cacheKey];
        }

        $salesBoards = $this->salesBoardPositionReader->fromLoadedSalesBoards(
            emissionId: (int) $this->emission->getKey(),
            salesBoards: $this->emission->salesBoards,
            constructions: $this->emission->constructions,
            positionDate: CarbonImmutable::parse($referenceMonth),
        )->salesBoards();

        if ($constructionId !== null) {
            $salesBoards = $salesBoards
                ->filter(fn (SalesBoard $salesBoard): bool => (int) $salesBoard->construction_id === $constructionId)
                ->values();
        }

        return $this->salesBoardCache[$cacheKey] = $salesBoards;
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
