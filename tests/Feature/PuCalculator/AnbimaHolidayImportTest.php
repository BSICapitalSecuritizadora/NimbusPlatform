<?php

use App\Domain\PuCalculator\Exceptions\AnbimaHolidayImportException;
use App\Domain\PuCalculator\Services\AnbimaHolidayImporter;
use App\Domain\PuCalculator\Services\BusinessCalendarOverrideService;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * Gera uma planilha no formato da ANBIMA (cabeçalho, colunas Data | Dia da Semana | Feriado,
 * datas como células de data reais + uma como texto, e uma nota de rodapé sem data) em .xls (BIFF)
 * ou .xlsx, persistida num caminho temporário.
 *
 * @param  list<array{0:string,1:string,2:string}>  $rows  data (Y-m-d), dia da semana, nome
 */
function makeAnbimaWorkbook(array $rows, string $format = 'xls', bool $lastDateAsText = false): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Feriados');

    $sheet->setCellValue('A1', 'Feriados Nacionais');
    $sheet->setCellValue('A2', 'Data');
    $sheet->setCellValue('B2', 'Dia da Semana');
    $sheet->setCellValue('C2', 'Feriado');

    $line = 3;
    $count = count($rows);

    foreach ($rows as $index => [$date, $weekday, $name]) {
        $carbon = CarbonImmutable::parse($date);

        if ($lastDateAsText && $index === $count - 1) {
            $sheet->setCellValue('A'.$line, $carbon->format('d/m/Y'));
        } else {
            $sheet->setCellValue('A'.$line, SpreadsheetDate::PHPToExcel($carbon->toDateTimeString()));
            $sheet->getStyle('A'.$line)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        }

        $sheet->setCellValue('B'.$line, $weekday);
        $sheet->setCellValue('C'.$line, $name);
        $line++;
    }

    $sheet->setCellValue('A'.($line + 1), 'O calendario nao inclui os feriados municipais nem eleicoes.');

    $path = temporaryTestFilePath('anbima-workbook', $format);
    $writer = $format === 'xlsx' ? new Xlsx($spreadsheet) : new Xls($spreadsheet);
    $writer->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

/** @return list<array{0:string,1:string,2:string}> */
function sampleHolidays(): array
{
    return [
        ['2025-01-01', 'quarta-feira', 'Confraternização Universal'],
        ['2025-04-21', 'segunda-feira', 'Tiradentes'],
        ['2025-12-25', 'quinta-feira', 'Natal'],
    ];
}

function importer(): AnbimaHolidayImporter
{
    return app(AnbimaHolidayImporter::class);
}

it('imports holidays from a local .xls file and applies them to the calendar', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    $result = importer()->importFromFile($path, 'B3');

    expect($result->total)->toBe(3)
        ->and($result->imported)->toBe(3)
        ->and($result->skipped)->toBe(0)
        ->and($result->invalid)->toBe(0)
        ->and($result->calendarApplied)->toBe(3)
        ->and(BusinessHoliday::query()->count())->toBe(3);

    $natal = BusinessHoliday::query()->whereDate('holiday_date', '2025-12-25')->firstOrFail();
    expect($natal->name)->toBe('Natal')
        ->and($natal->source)->toBe('anbima');

    $calendarRow = BusinessCalendarDate::query()->whereDate('calendar_date', '2025-12-25')->firstOrFail();
    $decision = app(BusinessDayCalendarService::class)->explain(CarbonImmutable::parse('2025-12-25'), 'B3');
    expect($calendarRow->is_business_day)->toBeFalse()
        ->and($calendarRow->description)->toBe('Natal')
        ->and($decision->isBusinessDay)->toBeFalse()
        ->and($decision->source)->toBe('anbima')
        ->and($decision->document)->toBe(basename($path));
});

it('uses BR banking ANBIMA by default and leaves the legacy B3 dataset intact', function () {
    BusinessHoliday::query()->create([
        'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
        'holiday_date' => '2024-01-01',
        'name' => 'Legado preservado',
        'source' => 'anbima',
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
        'calendar_date' => '2024-01-01',
        'is_business_day' => false,
        'description' => 'Legado preservado',
    ]);
    $legacyHoliday = BusinessHoliday::query()->where('calendar_code', BusinessCalendarRegistry::LEGACY_B3)->firstOrFail()->toArray();
    $legacyDate = BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::LEGACY_B3)->firstOrFail()->toArray();
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    $result = importer()->importFromFile($path);

    expect($result->calendarCode)->toBe(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->and(BusinessHoliday::query()->where('calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA)->count())->toBe(3)
        ->and(BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA)->count())->toBe(3)
        ->and(BusinessHoliday::query()->where('calendar_code', BusinessCalendarRegistry::LEGACY_B3)->firstOrFail()->toArray())->toBe($legacyHoliday)
        ->and(BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::LEGACY_B3)->firstOrFail()->toArray())->toBe($legacyDate)
        ->and(BusinessHoliday::query()->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count())->toBe(0);
});

it('parses dates stored as text strings too', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls', lastDateAsText: true);

    $result = importer()->importFromFile($path, 'B3');

    expect($result->imported)->toBe(3)
        ->and(BusinessHoliday::query()->whereDate('holiday_date', '2025-12-25')->exists())->toBeTrue();
});

it('imports holidays downloaded from a URL with Http::fake', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');
    $bytes = file_get_contents($path);

    Http::fake([
        '*feriados_nacionais.xls' => Http::response($bytes, 200, ['Content-Type' => 'application/vnd.ms-excel']),
    ]);

    $result = importer()->importFromUrl(AnbimaHolidayImporter::DEFAULT_URL, 'B3');

    expect($result->imported)->toBe(3)
        ->and(BusinessHoliday::query()->count())->toBe(3);
});

it('throws a clear exception when the URL is unavailable', function () {
    Http::fake([
        '*' => Http::response('Service Unavailable', 503),
    ]);

    importer()->importFromUrl(AnbimaHolidayImporter::DEFAULT_URL, 'B3');
})->throws(AnbimaHolidayImportException::class, 'HTTP 503');

it('keeps the last applied calendar and audits an unavailable external source', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');
    importer()->importFromFile($path, BusinessCalendarRegistry::BR_BANKING_ANBIMA);
    Http::fake(['*' => Http::response('Service Unavailable', 503)]);

    expect(fn () => importer()->importFromUrl(
        AnbimaHolidayImporter::DEFAULT_URL,
        BusinessCalendarRegistry::BR_BANKING_ANBIMA,
    ))->toThrow(AnbimaHolidayImportException::class, 'HTTP 503');

    expect(BusinessHoliday::query()->count())->toBe(3)
        ->and(BusinessCalendarDate::query()->count())->toBe(3)
        ->and(BusinessCalendarImportRun::query()->where('result', BusinessCalendarImportRun::RESULT_FAILED)->whereNull('year')->count())->toBe(1);
});

it('throws a clear exception for an invalid/unreadable file', function () {
    $path = temporaryTestFilePath('anbima-invalid', 'xls');
    file_put_contents($path, 'this is not a spreadsheet');

    importer()->importFromFile($path, 'B3');
})->throws(AnbimaHolidayImportException::class);

it('does not change calendar data on a dry run and still audits the execution', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    $result = importer()->importFromFile($path, 'B3', dryRun: true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->imported)->toBe(3)
        ->and($result->calendarApplied)->toBe(0)
        ->and(BusinessHoliday::query()->count())->toBe(0)
        ->and(BusinessCalendarDate::query()->count())->toBe(0)
        ->and(BusinessCalendarImportRun::query()->where('dry_run', true)->count())->toBe(1);
});

it('is idempotent and does not duplicate holidays on re-import', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    $first = importer()->importFromFile($path, 'B3');
    $second = importer()->importFromFile($path, 'B3');

    expect($first->imported)->toBe(3)
        ->and($second->imported)->toBe(0)
        ->and($second->skipped)->toBe(3)
        ->and(BusinessHoliday::query()->count())->toBe(3);
});

it('audits every idempotent execution without incrementing the annual revision', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    importer()->importFromFile($path, BusinessCalendarRegistry::BR_BANKING_ANBIMA);
    $firstRevision = BusinessCalendarYear::query()->value('revision');
    importer()->importFromFile($path, BusinessCalendarRegistry::BR_BANKING_ANBIMA);

    $runs = BusinessCalendarImportRun::query()->oldest('id')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs->last()?->records_inserted)->toBe(0)
        ->and($runs->last()?->records_changed)->toBe(0)
        ->and($runs->last()?->removals_detected)->toBe(0)
        ->and($runs->last()?->conflicts_detected)->toBe(0)
        ->and($runs->last()?->result)->toBe(BusinessCalendarImportRun::RESULT_SUCCEEDED)
        ->and(BusinessCalendarYear::query()->value('revision'))->toBe($firstRevision);
});

it('detects source removals without reopening historical dates automatically', function () {
    $firstPath = makeAnbimaWorkbook(sampleHolidays(), 'xls');
    importer()->importFromFile($firstPath, BusinessCalendarRegistry::BR_BANKING_ANBIMA);

    $secondPath = makeAnbimaWorkbook(array_slice(sampleHolidays(), 0, 2), 'xls');
    $result = importer()->importFromFile($secondPath, BusinessCalendarRegistry::BR_BANKING_ANBIMA);

    $removedHoliday = BusinessHoliday::query()->whereDate('holiday_date', '2025-12-25')->firstOrFail();
    $effectiveDate = BusinessCalendarDate::query()->whereDate('calendar_date', '2025-12-25')->firstOrFail();

    expect($result->removalsDetected)->toBe(1)
        ->and($result->conflictsDetected)->toBeGreaterThanOrEqual(1)
        ->and($removedHoliday->removed_detected_at)->not()->toBeNull()
        ->and($effectiveDate->is_business_day)->toBeFalse()
        ->and(BusinessCalendarYear::query()->value('status'))->toBe(BusinessCalendarYear::STATUS_STALE);
});

it('rolls back holidays and effective dates together when applying the calendar fails', function () {
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_business_calendar_insert
        BEFORE INSERT ON business_calendar_dates
        BEGIN
            SELECT RAISE(ABORT, 'forced calendar failure');
        END
        SQL);

    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    expect(fn () => importer()->importFromFile($path, BusinessCalendarRegistry::BR_BANKING_ANBIMA))
        ->toThrow(AnbimaHolidayImportException::class, 'revertida integralmente');

    expect(BusinessHoliday::query()->count())->toBe(0)
        ->and(BusinessCalendarDate::query()->count())->toBe(0)
        ->and(BusinessCalendarYear::query()->count())->toBe(0)
        ->and(BusinessCalendarImportRun::query()->where('result', BusinessCalendarImportRun::RESULT_FAILED)->count())->toBe(1);
});

it('refuses a concurrent import and records the failed attempt', function () {
    config()->set('pu_calculator.business_calendar.lock_wait_seconds', 0);
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');
    $lock = Cache::lock(
        app(BusinessCalendarYearService::class)->lockKey(BusinessCalendarRegistry::BR_BANKING_ANBIMA),
        60,
    );
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => importer()->importFromFile($path, BusinessCalendarRegistry::BR_BANKING_ANBIMA))
            ->toThrow(AnbimaHolidayImportException::class, 'revertida integralmente');
    } finally {
        $lock->release();
    }

    expect(BusinessHoliday::query()->count())->toBe(0)
        ->and(BusinessCalendarImportRun::query()->where('result', BusinessCalendarImportRun::RESULT_FAILED)->count())->toBe(1);
});

it('does not allow ANBIMA data in the B3 listed trading calendar', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    expect(fn () => importer()->importFromFile($path, BusinessCalendarRegistry::B3_LISTED_TRADING))
        ->toThrow(AnbimaHolidayImportException::class, 'não pode receber feriados bancários ANBIMA');
});

it('never overwrites a manual override during a repeated import', function () {
    $path = makeAnbimaWorkbook([
        ['2025-01-01', 'quarta-feira', 'Confraternização Universal'],
    ], 'xls');
    importer()->importFromFile($path, BusinessCalendarRegistry::BR_BANKING_ANBIMA);
    $user = User::factory()->create();

    app(BusinessCalendarOverrideService::class)->apply(
        BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        CarbonImmutable::parse('2025-01-01'),
        true,
        'Sessão bancária excepcional documentada para teste.',
        $user->id,
    );

    $result = importer()->importFromFile($path, BusinessCalendarRegistry::BR_BANKING_ANBIMA);
    $calendarDate = BusinessCalendarDate::query()->whereDate('calendar_date', '2025-01-01')->firstOrFail();

    expect($calendarDate->is_business_day)->toBeTrue()
        ->and($calendarDate->data_origin)->toBe('manual_override')
        ->and($result->conflictsDetected)->toBe(1)
        ->and(BusinessCalendarYear::query()->value('status'))->toBe(BusinessCalendarYear::STATUS_STALE);
});

it('updates names only with the force flag', function () {
    $path = makeAnbimaWorkbook([
        ['2025-01-01', 'quarta-feira', 'Ano Novo'],
    ], 'xls');
    importer()->importFromFile($path, 'B3');

    $renamed = makeAnbimaWorkbook([
        ['2025-01-01', 'quarta-feira', 'Confraternização Universal'],
    ], 'xls');

    $withoutForce = importer()->importFromFile($renamed, 'B3');
    expect($withoutForce->updated)->toBe(0)
        ->and($withoutForce->skipped)->toBe(1)
        ->and(BusinessHoliday::query()->whereDate('holiday_date', '2025-01-01')->value('name'))->toBe('Ano Novo');

    $withForce = importer()->importFromFile($renamed, 'B3', force: true);
    expect($withForce->updated)->toBe(1)
        ->and(BusinessHoliday::query()->whereDate('holiday_date', '2025-01-01')->value('name'))->toBe('Confraternização Universal');
});

it('imports through the artisan command from a file', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    $this->artisan('pu:holidays:import-anbima', ['--file' => $path, '--calendar' => 'B3'])
        ->expectsOutputToContain('Criados: 3')
        ->assertExitCode(0);

    expect(BusinessHoliday::query()->count())->toBe(3);
});

it('targets BR banking ANBIMA from the command when no calendar is passed', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    $this->artisan('pu:holidays:import-anbima', ['--file' => $path])
        ->expectsOutputToContain(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->assertExitCode(0);

    expect(BusinessHoliday::query()->where('calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA)->count())->toBe(3)
        ->and(BusinessHoliday::query()->where('calendar_code', BusinessCalendarRegistry::LEGACY_B3)->count())->toBe(0);
});

it('supports dry-run through the artisan command', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    $this->artisan('pu:holidays:import-anbima', ['--file' => $path, '--dry-run' => true])
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    expect(BusinessHoliday::query()->count())->toBe(0);
});
