<?php

use App\Actions\Receivables\ImportReceivablesFromSpreadsheet;
use App\Filament\Resources\Receivables\Pages\CreateReceivable;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Models\Emission;
use App\Models\Receivable;
use App\Models\User;
use App\Rules\ReceivablesSpreadsheetFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action as FilamentAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows the import and create actions with the expected filters on the receivables list page', function () {
    $this->actingAs(makeReceivableAdminUser());

    Livewire::test(ListReceivables::class)
        ->assertActionExists('import')
        ->assertActionHasLabel('import', 'Importar Planilha')
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Criar resumo')
        ->assertTableFilterExists('emission_id')
        ->assertTableFilterExists('reference_month')
        ->assertTableFilterExists('portfolio_id');
});

it('configures the receivables import action to validate xlsx spreadsheets only on the backend rule', function () {
    $this->actingAs(makeReceivableAdminUser());

    $component = Livewire::test(ListReceivables::class)->instance();
    $method = new ReflectionMethod($component, 'getHeaderActions');
    $method->setAccessible(true);

    /** @var FilamentAction|null $action */
    $action = collect($method->invoke($component))
        ->first(fn (FilamentAction $action): bool => $action->getName() === 'import');

    $schema = $action?->getSchema(Schema::make($component));
    $field = $schema?->getFlatFields(withHidden: true)['file'] ?? null;
    $rules = [];

    if ($field instanceof FileUpload) {
        $rulesProperty = new ReflectionProperty($field, 'rules');
        $rulesProperty->setAccessible(true);
        $rules = $rulesProperty->getValue($field);
    }

    expect($action)->not->toBeNull()
        ->and($field)->toBeInstanceOf(FileUpload::class)
        ->and($field->getExtraInputAttributes()['accept'] ?? null)->toBeNull()
        ->and(collect($rules)->contains(fn (mixed $rule): bool => data_get($rule, '0') instanceof ReceivablesSpreadsheetFile))->toBeTrue();
});

it('accepts valid xlsx spreadsheets through the receivables spreadsheet rule', function () {
    $spreadsheetPath = storeReceivableSummarySpreadsheet(makeSummaryRows());

    $file = new UploadedFile(
        Storage::disk('local')->path($spreadsheetPath),
        'receivables-summary.xlsx',
        null,
        null,
        true,
    );

    $validator = validator(
        ['file' => $file],
        ['file' => ['file', new ReceivablesSpreadsheetFile]],
    );

    expect($validator->passes())->toBeTrue()
        ->and($validator->errors()->all())->toBe([]);
});

it('rejects invalid spreadsheets through the receivables spreadsheet rule', function () {
    Storage::disk('local')->makeDirectory('imports/testing');
    Storage::disk('local')->put('imports/testing/receivables-invalid.xlsx', 'not a real spreadsheet');

    $file = new UploadedFile(
        Storage::disk('local')->path('imports/testing/receivables-invalid.xlsx'),
        'receivables-invalid.xlsx',
        null,
        null,
        true,
    );

    $validator = validator(
        ['file' => $file],
        ['file' => ['file', new ReceivablesSpreadsheetFile]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('file'))->toBe('Envie uma planilha Excel valida no formato .xlsx.');
});

it('normalizes reference months from DateTime instances', function () {
    expect(Receivable::normalizeReferenceMonth(new \DateTimeImmutable('2026-03-31 00:00:00')))
        ->toBe('2026-03-01');
});

it('resolves uploaded spreadsheets from stored paths and uploaded files', function () {
    $this->actingAs(makeReceivableAdminUser());

    $spreadsheetPath = storeReceivableSummarySpreadsheet(makeSummaryRows());

    $component = Livewire::test(ListReceivables::class)->instance();
    $method = new ReflectionMethod($component, 'resolveUploadedSpreadsheetPath');
    $method->setAccessible(true);

    $uploadedFile = new UploadedFile(
        Storage::disk('local')->path($spreadsheetPath),
        'receivables-summary.xlsx',
        null,
        null,
        true,
    );

    expect($method->invoke($component, $spreadsheetPath))
        ->toBe(Storage::disk('local')->path($spreadsheetPath))
        ->and($method->invoke($component, $uploadedFile))
        ->toBe($uploadedFile->getRealPath());
});

it('shows a persistent notification with the import validation message', function () {
    $this->actingAs(makeReceivableAdminUser());

    $component = Livewire::test(ListReceivables::class)->instance();
    $method = new ReflectionMethod($component, 'notifyImportValidationFailure');
    $method->setAccessible(true);
    $exception = ValidationException::withMessages([
        'file' => [
            'Nao foi encontrada nenhuma aba valida para importacao.',
            'Ajuste o arquivo para que ele contenha a aba com o nome "Resumo".',
        ],
    ]);

    $method->invoke($component, $exception);

    Notification::assertNotified(
        Notification::make()
            ->title('Importacao nao realizada')
            ->body("Nao foi encontrada nenhuma aba valida para importacao.\nAjuste o arquivo para que ele contenha a aba com o nome \"Resumo\".")
            ->danger()
            ->persistent(),
    );
});

it('creates a receivable summary linked to the selected emission', function () {
    $this->actingAs(makeReceivableAdminUser());

    $emission = Emission::factory()->create([
        'name' => 'CRI Resumo Manual',
    ]);

    Livewire::test(CreateReceivable::class)
        ->fillForm(makeReceivableSummaryFormData($emission->id))
        ->call('create')
        ->assertHasNoFormErrors();

    $receivable = Receivable::query()->first();

    expect($receivable)->not->toBeNull()
        ->and($receivable?->emission_id)->toBe($emission->id)
        ->and($receivable?->reference_month?->toDateString())->toBe('2026-03-01')
        ->and($receivable?->portfolio_id)->toBe('98')
        ->and($receivable?->active_contracts_count)->toBe(131)
        ->and($receivable?->expected_interest_amount)->toBe('7307.63')
        ->and($receivable?->total_outstanding_balance_amount)->toBe('28329736.81')
        ->and($receivable?->sale_ltv_ratio)->toBe('1.765278')
        ->and($receivable?->average_rate_details)->toContain('INCC-DI');
});

it('requires the receivable summary mandatory fields', function () {
    $this->actingAs(makeReceivableAdminUser());

    Livewire::test(CreateReceivable::class)
        ->call('create')
        ->assertHasFormErrors([
            'emission_id' => 'required',
            'reference_month' => 'required',
            'portfolio_id' => 'required',
            'active_contracts_count' => 'required',
            'average_rate_details' => 'required',
            'expected_interest_amount' => 'required',
            'expected_amortization_amount' => 'required',
            'total_outstanding_balance_amount' => 'required',
        ]);
});

it('prevents duplicate manual summaries for the same emission and competence', function () {
    $this->actingAs(makeReceivableAdminUser());

    $emission = Emission::factory()->create();
    Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-03-01',
    ]);

    Livewire::test(CreateReceivable::class)
        ->fillForm(makeReceivableSummaryFormData($emission->id))
        ->call('create')
        ->assertHasFormErrors(['reference_month']);
});

it('filters receivable summaries by emission', function () {
    $this->actingAs(makeReceivableAdminUser());

    $selectedEmission = Emission::factory()->create([
        'name' => 'CRI Conviva',
    ]);
    $otherEmission = Emission::factory()->create([
        'name' => 'CRI Atlas',
    ]);

    $selectedReceivable = Receivable::factory()->create([
        'emission_id' => $selectedEmission->id,
    ]);
    $otherReceivable = Receivable::factory()->create([
        'emission_id' => $otherEmission->id,
    ]);

    Livewire::test(ListReceivables::class)
        ->assertCanSeeTableRecords([$selectedReceivable, $otherReceivable])
        ->filterTable('emission_id', $selectedEmission->id)
        ->assertCanSeeTableRecords([$selectedReceivable])
        ->assertCanNotSeeTableRecords([$otherReceivable]);
});

it('imports a receivable summary from the resumo sheet only and binds it to the selected emission', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Importacao CVM',
    ]);

    $spreadsheetPath = storeReceivableSummarySpreadsheet(
        makeSummaryRows(),
        withExtraRecebimentoSheet: true,
    );

    $result = app(ImportReceivablesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $receivable = Receivable::query()->where('emission_id', $emission->id)->first();

    expect($result['created'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        ->and($result['total'])->toBe(1)
        ->and($receivable)->not->toBeNull()
        ->and($receivable?->portfolio_id)->toBe('98')
        ->and($receivable?->active_contracts_count)->toBe(131)
        ->and($receivable?->expected_interest_amount)->toBe('7307.63')
        ->and($receivable?->total_default_balance_amount)->toBe('598656.76')
        ->and($receivable?->total_outstanding_balance_amount)->toBe('28329736.81')
        ->and($receivable?->sale_ltv_ratio)->toBe('1.765278')
        ->and($receivable?->average_rate_details)->toBe("INCC-DI - 11.33% a.a\nIPCA - 10.28% a.a")
        ->and($receivable?->summary_payload)->toBeArray()
        ->and($receivable?->summary_payload)->toHaveCount(56);
});

it('imports a receivable summary from the Planilha1 sheet when Resumo is missing', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Importacao Planilha1',
    ]);

    $spreadsheetPath = storeReceivableSummarySpreadsheet(makeSummaryRows(), summarySheetName: 'Planilha1');

    $result = app(ImportReceivablesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $receivable = Receivable::query()->where('emission_id', $emission->id)->sole();

    expect($result['created'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        ->and($receivable->reference_month?->toDateString())->toBe('2026-03-01')
        ->and($receivable->portfolio_id)->toBe('98')
        ->and($receivable->average_rate_details)->toBe("INCC-DI - 11.33% a.a\nIPCA - 10.28% a.a");
});

it('imports a receivable summary from the Plan1 sheet when Resumo is missing', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Importacao Plan1',
    ]);

    $spreadsheetPath = storeReceivableSummarySpreadsheet(makeAlternativeSummaryRows(), summarySheetName: 'Plan1');

    $result = app(ImportReceivablesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $receivable = Receivable::query()->where('emission_id', $emission->id)->sole();

    expect($result['created'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        ->and($receivable->reference_month?->toDateString())->toBe('2026-04-01')
        ->and($receivable->portfolio_id)->toContain('RESIDENCIAL RIO BRANCO')
        ->and($receivable->linked_credits_current_amount)->toBe('258196.41');
});

it('imports alternative resumo layouts with a textual carteira and derived linked credits current amount', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Importacao Alternativa',
    ]);

    $spreadsheetPath = storeReceivableSummarySpreadsheet(makeAlternativeSummaryRows());

    $result = app(ImportReceivablesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $receivable = Receivable::query()->where('emission_id', $emission->id)->sole();

    expect($result['created'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        ->and($receivable->reference_month?->toDateString())->toBe('2026-04-01')
        ->and($receivable->portfolio_id)->toBe('RESIDENCIAL RIO BRANCO EMPREENDIMENTO IMOBILIÁRIO SPE LTDA.')
        ->and($receivable->active_contracts_count)->toBe(23)
        ->and($receivable->linked_credits_current_amount)->toBe('258196.41')
        ->and($receivable->portfolio_ltv_ratio)->toBe('0.684164')
        ->and($receivable->sale_ltv_ratio)->toBeNull()
        ->and($receivable->top_five_debtors_concentration_ratio)->toBeNull()
        ->and($receivable->average_rate_details)->toBe('FIXA')
        ->and($receivable->summary_payload)->toBeArray()
        ->and($receivable->summary_payload)->toHaveCount(67);
});

it('updates the existing receivable summary when importing the same emission and competence again', function () {
    $emission = Emission::factory()->create();

    $firstSpreadsheetPath = storeReceivableSummarySpreadsheet(makeSummaryRows());
    $secondSpreadsheetPath = storeReceivableSummarySpreadsheet(makeSummaryRows([
        'Esperado a receber no Mes de Juros' => 9000.11,
        'Saldo devedor Total' => 30000000.55,
    ]));

    app(ImportReceivablesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($firstSpreadsheetPath),
        $emission,
    );

    $result = app(ImportReceivablesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($secondSpreadsheetPath),
        $emission,
    );

    $receivable = Receivable::query()->where('emission_id', $emission->id)->sole();

    expect($result['created'])->toBe(0)
        ->and($result['updated'])->toBe(1)
        ->and(Receivable::query()->count())->toBe(1)
        ->and($receivable->expected_interest_amount)->toBe('9000.11')
        ->and($receivable->total_outstanding_balance_amount)->toBe('30000000.55');
});

it('rejects spreadsheets without Resumo, Planilha1 or Plan1', function () {
    $emission = Emission::factory()->create();
    $spreadsheetPath = storeWorkbookWithoutResumoSheet();

    try {
        app(ImportReceivablesFromSpreadsheet::class)->handle(
            Storage::disk('local')->path($spreadsheetPath),
            $emission,
        );

        $this->fail('A importacao deveria rejeitar planilhas sem uma aba valida de resumo.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('file')
            ->and($exception->errors()['file'][0])->toContain('nenhuma aba valida')
            ->and($exception->errors()['file'][1])->toContain('aba com o nome "Resumo"');
    }
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<int, array<int, mixed>>
 */
function makeSummaryRows(array $overrides = []): array
{
    $rows = [
        ['Mes ano referencia', '2026-03-31', null],
        ['ID da Carteira', 98, null],
        ['Numero de contratos ativos', 131, null],
        ['Esperado a receber no Mes de Juros', 7307.63, null],
        ['Esperado de Receber no Mes de Amortizacao', 950402.10, null],
        ['Recebido no Mes de parcelas do Mes - Juros', 4841.44, null],
        ['Recebido no Mes de parcelas do Mes - Amortizacao', 530573.38, null],
        ['Recebido no Mes de Antecipacao - Juros', 200.30, null],
        ['Recebido no Mes de Antecipacao - Amortizacao', 34295.52, null],
        ['Recebido no mes de Inadimplencia - Juros', 875.20, null],
        ['Recebido no mes de Inadimplencia - Amortizacao', 121344.20, null],
        ['Recebido no Mes de Juros e Mora', 291.86, null],
        ['Saldo devedor da carteria Adimplente pre evento do mes', 21143330.25, null],
        ['Saldo devedor da carteria Inadimplente pre evento do mes', 6587749.80, null],
        ['Saldo devedor da carteria Adimplente pos evento do mes', 29967754.77, null],
        ['Saldo devedor da carteria Inadimplente pos evento do mes', 909400.43, null],
        ['Saldo Inadimplencia Mes', 292160.79, null],
        ['Saldo Inadimplencia Geral', 598656.76, null],
        ['Creditos vinculados em dia', 27731080.05, null],
        ['Vencidos E Nao Pagos Ate 30 Dias', 282995.60, null],
        ['Vencidos E Nao Pagos De 31 A 60 Dias', 71114.28, null],
        ['Vencidos E Nao Pagos Ds 61 A 90 Dias', 37366.07, null],
        ['Vencidos E Nao Pagos De 91 A 120 Dias', 49628.64, null],
        ['Vencidos E Nao Pagos De 121 A 150 Dias', 17197.18, null],
        ['Vencidos E Nao Pagos De 151 A 180 Dias', 58381.03, null],
        ['Vencidos E Nao Pagos De 181 A 360 Dias', 56123.76, null],
        ['Vencidos E Nao Pagos Acima De 360 Dias', 25850.20, null],
        ['Pagos Antecipadamente Ate 30 Dias', 27211.42, null],
        ['Pagos Antecipadamente De 31 A 60 Dias', 7284.40, null],
        ['Pagos Antecipadamente Ds 61 A 90 Dias', 0, null],
        ['Pagos Antecipadamente De 91 A 120 Dias', 0, null],
        ['Pagos Antecipadamente De 121 A 150 Dias', 0, null],
        ['Pagos Antecipadamente De 151 A 180 Dias', 0, null],
        ['Pagos Antecipadamente De 181 A 360 Dias', 0, null],
        ['Pagos Antecipadamente Acima De 360 Dias', 0, null],
        ['Creditos Vinculados Ate 30 Dias', 677460.59, null],
        ['Creditos Vinculados De 31 A 60 Dias', 549864.57, null],
        ['Creditos Vinculados Ds 61 A 90 Dias', 838581.34, null],
        ['Creditos Vinculados De 91 A 120 Dias', 760076.64, null],
        ['Creditos Vinculados De 121 A 150 Dias', 642294.49, null],
        ['Creditos Vinculados De 151 A 180 Dias', 696501.45, null],
        ['Creditos Vinculados De 181 A 360 Dias', 10644039.28, null],
        ['Creditos Vinculados Acima De 360 Dias', 12922261.69, null],
        ['Valor das garantias incorporadas ao PL do CRI', null, null],
        ['Valor total de pre-pagamento no mes', 38151.42, null],
        ['% De Concentracao Dos 5 Maiores Devedores', null, null],
        ['Os cinco maiores devedores', null, null],
        ['Nome e CNPJ de cada Devedor', null, null],
        ['Participacao em relacao a Oferta', null, null],
        ['Saldo devedor Total', 28329736.81, null],
        ['LTV', null, null],
        ['LTV Venda', 1.765278, null],
        ['Duration Carteira (Anos)', 1.399974, null],
        ['Duration Carteira (Meses)', 16.799698, null],
        ['Taxa Media da Carteira', 'INCC-DI - 11.33% a.a', null],
        [null, 'IPCA - 10.28% a.a', null],
    ];

    foreach ($rows as &$row) {
        $label = $row[0];

        if (($label !== null) && array_key_exists($label, $overrides)) {
            $row[1] = $overrides[$label];
        }
    }

    return $rows;
}

/**
 * @return array<int, array<int, mixed>>
 */
function makeAlternativeSummaryRows(): array
{
    return [
        ['Mês ano referência', '2026-04-30', null],
        ['ID da Carteira', 'RESIDENCIAL RIO BRANCO EMPREENDIMENTO IMOBILIÁRIO SPE LTDA.', null],
        ['Número de contratos ativos', 23, null],
        ['Esperado a receber no Mês de Juros', 0, null],
        ['Esperado de Receber no Mês de Amortização', 13106.908793851073, null],
        ['Recebido no Mês de parcelas do Mês - Juros', 0, null],
        ['Recebido no Mês de parcelas do Mês - Amortização', 3259.48, null],
        ['Recebido no Mês de Antecipação - Juros', 0, 'Recebíveis '],
        ['Recebido no Mês de Antecipação - Amortização', 0, 'LP'],
        ['Recebido no mês de Inadimplência - Juros', 0, 'CP'],
        ['Recebido no mês de Inadimplência - Amortização', 1355.52, 'Atraso Total'],
        ['Recebido no Mês de Juros e Mora', 31.79, 'Créditos Total'],
        ['Saldo devedor da carteria Adimplente pré evento do mês', 849963.8299999998, null],
        ['Saldo devedor da carteria Inadimplente pré evento do mês', 2036590.6039795412, null],
        ['Saldo devedor da carteria Adimplente pós evento do mês', 836683.3600000001, null],
        ['Saldo devedor da carteria Inadimplente pós evento do mês', 2048776.9126407746, null],
        ['Saldo Inadimplência Mês', 9847.428793851073, null],
        ['Saldo Inadimplência Geral', 2048776.9126407746, null],
        ['Vencidos E Não Pagos Até 30 Dias', 10023.030083393069, null],
        ['Vencidos E Não Pagos De 31 A 60 Dias', 10165.783706553593, null],
        ['Vencidos E Não Pagos Ds 61 A 90 Dias', 8954.354541338353, null],
        ['Vencidos E Não Pagos De 91 A 120 Dias', 9032.59019629224, null],
        ['Vencidos E Não Pagos De 121 A 150 Dias', 184720.10745345222, null],
        ['Vencidos E Não Pagos De 151 A 180 Dias', 9207.20129084184, null],
        ['Vencidos E Não Pagos De 181 A 360 Dias', 0, null],
        ['Vencidos E Não Pagos Acima de 360 Dias', 0, null],
        ['Vencidos E Não Pagos Acima de 180 Dias*', 1816673.8453689031, null],
        ['Pagos Antecipadamente Até 30 Dias', 0, null],
        ['Pagos Antecipadamente De 31 A 60 Dias', 0, null],
        ['Pagos Antecipadamente Ds 61 A 90 Dias', 0, null],
        ['Pagos Antecipadamente De 91 A 120 Dias', 0, null],
        ['Pagos Antecipadamente De 121 A 150 Dias', 0, null],
        ['Pagos Antecipadamente De 151 A 180 Dias', 0, null],
        ['Pagos Antecipadamente De 181 A 360 Dias', 0, null],
        ['Pagos Antecipadamente Acima de 360 Dias', 0, null],
        ['Pagos Antecipadamente Acima de 180 Dias*', 0, null],
        ['Créditos Vinculados Até 30 Dias', 13106.93, null],
        ['Créditos Vinculados De 31 A 60 Dias', 16098.289999999999, null],
        ['Créditos Vinculados Ds 61 A 90 Dias', 191107.86, null],
        ['Créditos Vinculados De 91 A 120 Dias', 13707.86, null],
        ['Créditos Vinculados De 121 A 150 Dias', 10467.61, null],
        ['Créditos Vinculados De 151 A 180 Dias', 13707.86, null],
        ['Créditos Vinculados De 181 A 360 Dias', 0, null],
        ['Créditos Vinculados Acima de 360 Dias', 0, null],
        ['Créditos Vinculados Acima de 180 Dias*', 578486.949999997, null],
        ['Valor das garantias incorporadas ao PL do CRI', 0, null],
        ['Valor total de pré-pagamento no mês', 0, null],
        ['Saldo devedor Total', 2885460.272640775, null],
        ['Devedor (>= 20%) Nome 1', '', null],
        ['Devedor (>= 20%) CPF/CNPJ 1', '', null],
        ['Devedor (>= 20%) Saldo 1', '', null],
        ['Devedor (>= 20%) Nome 2', '', null],
        ['Devedor (>= 20%) CPF/CNPJ 2', '', null],
        ['Devedor (>= 20%) Saldo 2', '', null],
        ['Devedor (>= 20%) Nome 3', '', null],
        ['Devedor (>= 20%) CPF/CNPJ 3', '', null],
        ['Devedor (>= 20%) Saldo 3', '', null],
        ['Devedor (>= 20%) Nome 4', '', null],
        ['Devedor (>= 20%) CPF/CNPJ 4', null, null],
        ['Devedor (>= 20%) Saldo 4', null, null],
        ['Devedor (>= 20%) Nome 5', null, null],
        ['Devedor (>= 20%) CPF/CNPJ 5', null, null],
        ['Devedor (>= 20%) Saldo 5', null, null],
        ['LTV', 0.6841635109348494, null],
        ['Duration Carteira (Anos)', 4, null],
        ['Duration Carteira (Meses)', 1, null],
        ['Taxa Média da Carteira', 'FIXA', null],
    ];
}

/**
 * @param  array<int, array<int, mixed>>  $summaryRows
 */
function storeReceivableSummarySpreadsheet(array $summaryRows, bool $withExtraRecebimentoSheet = false, string $summarySheetName = 'Resumo'): string
{
    Storage::disk('local')->makeDirectory('imports/testing');

    $relativePath = 'imports/testing/'.uniqid('recebiveis-resumo-', true).'.xlsx';
    $absolutePath = Storage::disk('local')->path($relativePath);

    $writer = SimpleExcelWriter::create($absolutePath);
    $writer
        ->noHeaderRow()
        ->nameCurrentSheet($summarySheetName)
        ->addRows($summaryRows);

    if ($withExtraRecebimentoSheet) {
        $writer
            ->addNewSheetAndMakeItCurrent('Recebimento')
            ->addRows([
                ['PROJETO', 'CLIENTE', 'Pago'],
                ['Nao deve ser usado', 'Importador do Resumo', 123.45],
            ]);
    }

    $writer->close();

    return $relativePath;
}

function storeWorkbookWithoutResumoSheet(): string
{
    Storage::disk('local')->makeDirectory('imports/testing');

    $relativePath = 'imports/testing/'.uniqid('recebiveis-sem-resumo-', true).'.xlsx';
    $absolutePath = Storage::disk('local')->path($relativePath);

    $writer = SimpleExcelWriter::create($absolutePath);
    $writer
        ->noHeaderRow()
        ->nameCurrentSheet('Recebimento')
        ->addRows([
            ['PROJETO', 'CLIENTE', 'Pago'],
            ['AMANI PRIVATE RESIDENCE', 'Cliente teste', 100],
        ]);
    $writer->close();

    return $relativePath;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function makeReceivableSummaryFormData(int $emissionId, array $overrides = []): array
{
    return array_merge([
        'emission_id' => $emissionId,
        'reference_month' => '03/2026',
        'portfolio_id' => '98',
        'active_contracts_count' => 131,
        'average_rate_details' => "INCC-DI - 11.33% a.a\nIPCA - 10.28% a.a",
        'expected_interest_amount' => '7.307,63',
        'expected_amortization_amount' => '950.402,10',
        'received_installment_interest_amount' => '4.841,44',
        'received_installment_amortization_amount' => '530.573,38',
        'received_prepayment_interest_amount' => '200,30',
        'received_prepayment_amortization_amount' => '34.295,52',
        'received_default_interest_amount' => '875,20',
        'received_default_amortization_amount' => '121.344,20',
        'received_interest_and_penalty_amount' => '291,86',
        'performing_balance_pre_event_amount' => '21.143.330,25',
        'non_performing_balance_pre_event_amount' => '6.587.749,80',
        'performing_balance_post_event_amount' => '29.967.754,77',
        'non_performing_balance_post_event_amount' => '909.400,43',
        'monthly_default_balance_amount' => '292.160,79',
        'total_default_balance_amount' => '598.656,76',
        'linked_credits_current_amount' => '27.731.080,05',
        'overdue_up_to_30_days_amount' => '282.995,60',
        'overdue_31_to_60_days_amount' => '71.114,28',
        'overdue_61_to_90_days_amount' => '37.366,07',
        'overdue_91_to_120_days_amount' => '49.628,64',
        'overdue_121_to_150_days_amount' => '17.197,18',
        'overdue_151_to_180_days_amount' => '58.381,03',
        'overdue_181_to_360_days_amount' => '56.123,76',
        'overdue_over_360_days_amount' => '25.850,20',
        'prepaid_up_to_30_days_amount' => '27.211,42',
        'prepaid_31_to_60_days_amount' => '7.284,40',
        'prepaid_61_to_90_days_amount' => '0,00',
        'prepaid_91_to_120_days_amount' => '0,00',
        'prepaid_121_to_150_days_amount' => '0,00',
        'prepaid_151_to_180_days_amount' => '0,00',
        'prepaid_181_to_360_days_amount' => '0,00',
        'prepaid_over_360_days_amount' => '0,00',
        'linked_credits_up_to_30_days_amount' => '677.460,59',
        'linked_credits_31_to_60_days_amount' => '549.864,57',
        'linked_credits_61_to_90_days_amount' => '838.581,34',
        'linked_credits_91_to_120_days_amount' => '760.076,64',
        'linked_credits_121_to_150_days_amount' => '642.294,49',
        'linked_credits_151_to_180_days_amount' => '696.501,45',
        'linked_credits_181_to_360_days_amount' => '10.644.039,28',
        'linked_credits_over_360_days_amount' => '12.922.261,69',
        'guarantees_value_amount' => null,
        'total_prepayment_amount' => '38.151,42',
        'top_five_debtors_concentration_ratio' => null,
        'total_outstanding_balance_amount' => '28.329.736,81',
        'portfolio_ltv_ratio' => null,
        'sale_ltv_ratio' => '176,5278',
        'portfolio_duration_years' => '1,399974',
        'portfolio_duration_months' => '16,799698',
    ], $overrides);
}

function makeReceivableAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
