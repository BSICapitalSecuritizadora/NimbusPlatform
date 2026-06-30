<?php

use App\Domain\PuCalculator\Exceptions\AnbimaHolidayImportException;
use App\Domain\PuCalculator\Services\AnbimaHolidayImporter;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $path = tempnam(sys_get_temp_dir(), 'anbima_test_').'.'.$format;
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

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/anbima_test_*') ?: [] as $file) {
        @unlink($file);
    }
});

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
    expect($calendarRow->is_business_day)->toBeFalse()
        ->and($calendarRow->description)->toBe('Natal');
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

it('throws a clear exception for an invalid/unreadable file', function () {
    $path = tempnam(sys_get_temp_dir(), 'anbima_test_').'.xls';
    file_put_contents($path, 'this is not a spreadsheet');

    importer()->importFromFile($path, 'B3');
})->throws(AnbimaHolidayImportException::class);

it('does not persist anything on a dry run', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    $result = importer()->importFromFile($path, 'B3', dryRun: true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->imported)->toBe(3)
        ->and($result->calendarApplied)->toBe(0)
        ->and(BusinessHoliday::query()->count())->toBe(0)
        ->and(BusinessCalendarDate::query()->count())->toBe(0);
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

it('supports dry-run through the artisan command', function () {
    $path = makeAnbimaWorkbook(sampleHolidays(), 'xls');

    $this->artisan('pu:holidays:import-anbima', ['--file' => $path, '--dry-run' => true])
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    expect(BusinessHoliday::query()->count())->toBe(0);
});
