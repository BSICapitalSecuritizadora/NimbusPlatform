<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardDerivedLine;
use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\Enums\ContractStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Services\SalesBoards\SalesBoardDerivationService;
use Carbon\CarbonImmutable;

/**
 * Monta os cenários da derivação sem repetir o mesmo `create()` em cada teste.
 *
 * Uma classe em vez de funções globais do Pest porque os cenários são usados por
 * vários arquivos de teste, e função global só existe no arquivo que a declara.
 */
final class DerivationFixture
{
    public static function derive(Construction $construction, string $referenceMonth = '2026-07-01'): SalesBoardDerivedPosition
    {
        return app(SalesBoardDerivationService::class)
            ->deriveForConstruction($construction, CarbonImmutable::parse($referenceMonth));
    }

    public static function construction(): Construction
    {
        return Construction::factory()->create(['emission_id' => Emission::factory()->create()->id]);
    }

    public static function unit(
        Construction $construction,
        string $unit,
        ?string $baseValue = '500000.00',
        ?string $referenceDate = '2026-01-01',
    ): ConstructionUnit {
        return ConstructionUnit::factory()->create([
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => $unit,
            'base_value' => $baseValue,
            'base_value_reference_date' => $baseValue === null ? null : $referenceDate,
        ]);
    }

    public static function contract(
        ConstructionUnit $unit,
        string $saleDate,
        string $saleValue = '600000.00',
        ?string $cancellationDate = null,
        ContractStatus $status = ContractStatus::Active,
    ): Contract {
        return Contract::factory()->create([
            'construction_unit_id' => $unit->id,
            'sale_date' => $saleDate,
            'sale_value' => $saleValue,
            'cancellation_date' => $cancellationDate,
            'status' => $status,
        ]);
    }

    public static function installment(
        Contract $contract,
        string $number,
        string $dueDate,
        string $expected,
        ?string $paymentDate = null,
        ?string $paid = null,
        ?string $cancellationDate = null,
    ): ContractInstallment {
        return ContractInstallment::factory()->create([
            'contract_id' => $contract->id,
            'number' => $number,
            'due_date' => $dueDate,
            'expected_value' => $expected,
            'payment_date' => $paymentDate,
            'paid_value' => $paid,
            'cancellation_date' => $cancellationDate,
        ]);
    }

    public static function lineFor(SalesBoardDerivedPosition $position, ConstructionUnit $unit): ?SalesBoardDerivedLine
    {
        return collect($position->lines)->firstWhere('constructionUnitId', $unit->id);
    }

    /**
     * @return list<string>
     */
    public static function issueCodes(SalesBoardDerivedPosition $position): array
    {
        return collect($position->issues)->map(fn ($issue): string => $issue->code->value)->all();
    }
}
