<?php

use App\Actions\ConstructionUnitValues\AnalyzeUnitValueSpreadsheet;
use App\Actions\ConstructionUnitValues\ImportUnitValuesFromSpreadsheet;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetColumns;
use App\Enums\UnitValueSource;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Services\SalesBoards\UnitValueResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * "Mesma vigência, vence o maior id" depende de como a coluna `date` é gravada
 * e ordenada: texto com hora no SQLite, data de verdade no MySQL. O arquivo roda
 * também no MySQL, e os dois bancos têm de responder o mesmo valor.
 */
pest()->group('parity');

/**
 * Unidade com valor base, mais um lançamento manual (pelo Eloquent) na vigência
 * de março.
 */
function tieBreakUnitWithManualValue(): ConstructionUnit
{
    [, $construction] = unitEmissionAndConstruction();

    $unit = DerivationFixture::unit($construction, '101', '400000.00', '2020-01-01');

    ConstructionUnitValue::query()->create([
        'construction_unit_id' => $unit->id,
        'value' => '500000.00',
        'effective_from' => '2026-03-01',
        'source' => UnitValueSource::Manual,
        'reason' => 'Lançamento manual',
    ]);

    return $unit;
}

/**
 * The spreadsheet correction is written after the manual value, for the same
 * effective date: it has the higher id and must prevail. On SQLite the bulk
 * insert used to write "2026-03-01" while the model wrote "2026-03-01
 * 00:00:00", the text ordering put the imported row first, and the manual
 * value won.
 */
it('lets a spreadsheet correction for the same effective date prevail over the manual value', function () {
    $unit = tieBreakUnitWithManualValue();

    $path = temporaryTestFilePath('unit-value-tie-break');
    $headers = UnitValueSpreadsheetColumns::headers();
    SimpleExcelWriter::create($path)
        ->addHeader($headers)
        ->addRow(array_combine($headers, ['CRI Conviva', 'Conviva Camboinhas', '01', '101', '450.000,00', '01/03/2026', 'Correção']))
        ->close();

    app(ImportUnitValuesFromSpreadsheet::class)->handle(app(AnalyzeUnitValueSpreadsheet::class)->handle($path));

    $imported = ConstructionUnitValue::query()->where('source', UnitValueSource::SpreadsheetImport)->sole();

    expect($imported->id)->toBeGreaterThan(ConstructionUnitValue::query()->where('source', UnitValueSource::Manual)->sole()->id)
        ->and(app(UnitValueResolver::class)->forUnit($unit, CarbonImmutable::parse('2026-05-31'))->valueCents)->toBe(45_000_000)
        ->and(DerivationFixture::derive($unit->construction->fresh(), '2026-05-01')->stockValueCents)->toBe(45_000_000);
});

/**
 * Rows already written by the old bulk insert keep their short text on SQLite.
 * The resolver orders by the civil date, not by the stored text, so the rule
 * holds whatever shape the row was written in.
 */
it('breaks the tie by id whatever text the effective date was stored with', function () {
    $unit = tieBreakUnitWithManualValue();

    DB::table('construction_unit_values')->insert([
        'construction_unit_id' => $unit->id,
        'value' => '470000.00',
        'effective_from' => '2026-03-01',
        'source' => UnitValueSource::SpreadsheetImport->value,
        'reason' => 'Correção antiga',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolver = app(UnitValueResolver::class);

    expect($resolver->forUnit($unit, CarbonImmutable::parse('2026-05-31'))->valueCents)->toBe(47_000_000)
        ->and($resolver->forUnitDates(collect([$unit]), [
            ['unit_id' => $unit->id, 'date' => CarbonImmutable::parse('2026-03-01')],
            ['unit_id' => $unit->id, 'date' => CarbonImmutable::parse('2026-02-28')],
        ]))->sequence(
            fn ($resolved) => $resolved->valueCents->toBe(47_000_000),
            fn ($resolved) => $resolved->valueCents->toBe(40_000_000),
        );
});
