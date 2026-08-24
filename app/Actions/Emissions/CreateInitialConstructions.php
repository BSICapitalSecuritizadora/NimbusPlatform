<?php

namespace App\Actions\Emissions;

use App\Filament\Resources\Emissions\Schemas\EmissionConstructionsStep;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persists the constructions captured during the emission creation wizard,
 * each one with its mandatory initial Sales Board.
 *
 * The emission must already exist -- a sales board needs both an emission and
 * one of its constructions -- so this runs right after the emission row is
 * created. Every construction is written together with its initial board: an
 * emission is never left with a construction that has no base position.
 */
class CreateInitialConstructions
{
    /**
     * @var list<string>
     */
    private const CONSTRUCTION_FIELDS = [
        'development_name',
        'development_trade_name',
        'development_cnpj',
        'city',
        'state',
        'construction_start_date',
        'construction_end_date',
        'estimated_value',
        'measurement_company_id',
    ];

    /**
     * @var list<string>
     */
    private const SALES_BOARD_FIELDS = [
        'reference_month',
        'stock_units',
        'financed_units',
        'paid_units',
        'exchanged_units',
        'stock_value',
        'financed_value',
        'paid_value',
        'exchanged_value',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $constructions
     * @return list<Construction>
     *
     * @throws InvalidArgumentException When the payload does not describe at least
     *                                  one construction with its initial sales board.
     */
    public function handle(Emission $emission, array $constructions): array
    {
        $constructions = array_values($constructions);

        if ($constructions === []) {
            throw new InvalidArgumentException('A operação precisa de ao menos um empreendimento.');
        }

        return DB::transaction(function () use ($emission, $constructions): array {
            return array_map(
                fn (array $data): Construction => $this->createConstruction($emission, $data),
                $constructions,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createConstruction(Emission $emission, array $data): Construction
    {
        $salesBoardData = Arr::wrap($data[EmissionConstructionsStep::SALES_BOARD_STATE_PATH] ?? []);

        if (blank($salesBoardData['reference_month'] ?? null)) {
            throw new InvalidArgumentException(
                'O empreendimento precisa de um quadro de vendas inicial com mês de referência.',
            );
        }

        $construction = $emission->constructions()->create(Arr::only($data, self::CONSTRUCTION_FIELDS));

        $this->createInitialSalesBoard($emission, $construction, $salesBoardData);

        return $construction;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createInitialSalesBoard(Emission $emission, Construction $construction, array $data): SalesBoard
    {
        return $emission->salesBoards()->create([
            ...Arr::only($data, self::SALES_BOARD_FIELDS),
            'construction_id' => $construction->getKey(),
        ]);
    }
}
