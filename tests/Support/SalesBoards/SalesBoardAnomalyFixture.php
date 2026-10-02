<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Services\SalesBoards\SalesBoardWriteGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monta a posição que o guard de escrita não deixa mais nascer.
 *
 * Desde a regra 3 do {@see SalesBoardWriteGuard}, um quadro só é gravado pelo
 * model sob a Emissão atual do empreendimento, e nunca ao lado de outro quadro
 * do mesmo empreendimento no mesmo mês. O que fica fora disso existe só como
 * dado anterior ao guard ou carregado por SQL -- e é exatamente o que o
 * diagnóstico (`sales-boards:position-drift`) e o leitor da posição continuam
 * precisando reconhecer.
 */
final class SalesBoardAnomalyFixture
{
    /**
     * Um quadro gravado direto na tabela, sem model, sem observer e sem versão
     * no histórico: é a carga por fora que o guard não alcança.
     *
     * Este é o único jeito de montar a anomalia nos testes. Uma factory ou um
     * `create()` com a Emissão errada passam pelo guard e são recusados.
     *
     * A competência é gravada no formato que o próprio model grava, com o dia
     * que vier: quem precisa de um dia diferente de 01 passa esse dia.
     *
     * @param  array<string, int|float|string>  $values  unidades e valores do quadro
     */
    public static function misplacedBoard(
        Emission $recordedUnder,
        Construction $construction,
        string $referenceMonth,
        array $values = [],
    ): SalesBoard {
        $row = [
            'stock_units' => 0,
            'financed_units' => 0,
            'paid_units' => 0,
            'exchanged_units' => 0,
            'stock_value' => '0.00',
            'financed_value' => '0.00',
            'paid_value' => '0.00',
            'exchanged_value' => '0.00',
            ...$values,
        ];

        $now = CarbonImmutable::now();

        $id = DB::table('sales_boards')->insertGetId([
            ...$row,
            'emission_id' => $recordedUnder->getKey(),
            'construction_id' => $construction->getKey(),
            'reference_month' => (new SalesBoard)->fromDateTime(CarbonImmutable::parse($referenceMonth)),
            'total_units' => (int) $row['stock_units'] + (int) $row['financed_units'] + (int) $row['paid_units'] + (int) $row['exchanged_units'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return SalesBoard::query()->findOrFail($id);
    }
}
