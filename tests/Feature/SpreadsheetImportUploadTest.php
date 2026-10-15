<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ViewEmission;
use App\Filament\Resources\IndexRates\Pages\ListIndexRates;
use App\Http\Middleware\ApplySpreadsheetImportUploadRules;
use App\Models\Emission;
use App\Models\EmissionPuSettlement;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 8.1 (NEW-01): CSV pelo navegador nas importações de índice e de
 * cronograma de pagamentos.
 *
 * O envio passa pelo protocolo real do Livewire -- o componente gera a URL, o
 * arquivo vai por HTTP ao endpoint de envio (middleware e regra de verdade) e o
 * componente conclui o envio -- e não pelo atalho de teste que pula o endpoint.
 * A regra global do envio temporário continua recusando CSV: só a URL assinada
 * com o perfil de importação, pedida por uma ação de importação visível, aceita.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    config(['livewire.temporary_file_upload.cleanup' => false]);
});

/**
 * @return array{status: int, url: string, errors: array<string, list<string>>|null}
 */
function siuUpload(Testable $component, string $name, UploadedFile $file): array
{
    $component->call('_startUpload', $name, [['name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'type' => 'text/csv']], false);

    $url = (string) collect(data_get($component->effects, 'dispatches'))
        ->firstWhere('name', 'upload:generatedSignedUrl')['params']['url'];

    $response = test()->withHeaders(['Accept' => 'application/json'])->post($url, ['files' => [$file]]);

    if ($response->status() === 200) {
        $component->call('_finishUpload', $name, $response->json('paths'), false);
    }

    return ['status' => $response->status(), 'url' => $url, 'errors' => $response->json('errors')];
}

function siuPost(string $url, UploadedFile $file): int
{
    return test()->withHeaders(['Accept' => 'application/json'])->post($url, ['files' => [$file]])->status();
}

/**
 * Arquivo de verdade: o MIME sai do conteúdo (finfo), como no navegador. O
 * `UploadedFile::fake()` responde o MIME pelo nome e faria um executável
 * renomeado para `.csv` passar por CSV.
 */
function siuFile(string $name, string $contents): UploadedFile
{
    $path = temporaryTestFilePath('siu-upload', pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

function siuImportUrl(): string
{
    return GenerateSignedUploadUrlFacade::signedRoute('livewire.upload-file', now()->addMinutes(5), ['purpose' => ApplySpreadsheetImportUploadRules::PURPOSE]);
}

/**
 * @param  list<AccessPermission>  $permissions
 */
function siuUser(array $permissions): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo(array_map(fn (AccessPermission $permission): string => $permission->value, $permissions));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function siuPaymentsComponent(Emission $emission, string $page = EditEmission::class): Testable
{
    return Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $emission, 'pageClass' => $page]);
}

/**
 * @param  list<list<string>>  $rows
 */
function siuXlsx(array $rows): UploadedFile
{
    $path = temporaryTestFilePath('siu-pagamentos');
    SimpleExcelWriter::create($path)->noHeaderRow()->addRows($rows)->close();

    return new UploadedFile($path, 'pagamentos.xlsx', null, null, true);
}

it('imports a published CDI CSV through the browser upload of the index page', function () {
    Fx::emission();
    $this->actingAs(siuUser([AccessPermission::PuIndexImport]));
    $component = Livewire::test(ListIndexRates::class)
        ->mountAction('importPublished')
        ->setActionData(['indexer' => PuIndexer::Cdi->value, 'source' => 'manual_import', 'confirm_outliers' => false]);

    $upload = siuUpload($component, 'mountedActions.0.data.csv_file.f1', siuFile('cdi.csv', "rate_date,rate_value\n2026-03-16,14.90\n2026-03-17,14.90\n"));

    expect($upload['status'])->toBe(200)
        ->and($upload['url'])->toContain('purpose='.ApplySpreadsheetImportUploadRules::PURPOSE);

    $component->callMountedAction()->assertHasNoActionErrors();

    expect((string) IndexRate::query()->whereDate('rate_date', '2026-03-16')->sole()->rate_value)->toBe('14.90000000')
        ->and(IndexRate::query()->whereDate('rate_date', '2026-03-17')->sole()->source)->toBe('manual_import');
});

it('keeps an implausible CSV value retained instead of recording it when uploaded from the browser', function () {
    Fx::emission();
    $this->actingAs(siuUser([AccessPermission::PuIndexImport]));
    $component = Livewire::test(ListIndexRates::class)
        ->mountAction('importPublished')
        ->setActionData(['indexer' => PuIndexer::Cdi->value, 'source' => 'manual_import', 'confirm_outliers' => false]);

    expect(siuUpload($component, 'mountedActions.0.data.csv_file.f1', siuFile('cdi.csv', "rate_date,rate_value\n2026-03-16,149.00\n"))['status'])->toBe(200);

    $component->callMountedAction();

    expect(IndexRate::query()->whereDate('rate_date', '2026-03-16')->exists())->toBeFalse();
});

it('explains a semicolon separated index CSV and records nothing', function () {
    Fx::emission();
    $this->actingAs(siuUser([AccessPermission::PuIndexImport]));
    $component = Livewire::test(ListIndexRates::class)
        ->mountAction('importPublished')
        ->setActionData(['indexer' => PuIndexer::Cdi->value, 'source' => 'manual_import', 'confirm_outliers' => false]);

    expect(siuUpload($component, 'mountedActions.0.data.csv_file.f1', siuFile('cdi.csv', "rate_date;rate_value\n2026-03-16;14,90\n"))['status'])->toBe(200);
    $before = IndexRate::query()->count();

    $component->callMountedAction();
    $body = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])->map(fn ($n) => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode(' ');

    expect($body)->toContain('Falha na importação', 'ponto e vírgula')
        ->and(IndexRate::query()->count())->toBe($before);
});

it('reads a first data line preceded by the UTF-8 byte order mark', function () {
    Fx::emission();
    $this->actingAs(siuUser([AccessPermission::PuIndexImport]));
    $component = Livewire::test(ListIndexRates::class)
        ->mountAction('importPublished')
        ->setActionData(['indexer' => PuIndexer::Cdi->value, 'source' => 'manual_import', 'confirm_outliers' => false]);

    expect(siuUpload($component, 'mountedActions.0.data.csv_file.f1', siuFile('cdi.csv', "\xEF\xBB\xBF2026-03-16,14.90\r\n2026-03-17,14.90\r\n"))['status'])->toBe(200);

    $component->callMountedAction();

    expect(IndexRate::query()->whereDate('rate_date', '2026-03-16')->exists())->toBeTrue()
        ->and(IndexRate::query()->whereDate('rate_date', '2026-03-17')->exists())->toBeTrue();
});

it('imports a payment schedule CSV from the browser as informed schedule only', function () {
    $emission = Emission::factory()->create();
    $this->actingAs(makeAdminUser());
    $component = siuPaymentsComponent($emission)->mountAction(TestAction::make('import')->table());
    $csv = "Data,Prêmio,Juros,Amortização,Amortização Extra\n2026-06-30,0,\"102.110,75\",326085,0\n30/07/2026,\"1.250,50\",127548.61,\"326.090,31\",\"10,90\"\n";

    $upload = siuUpload($component, 'mountedActions.0.data.file.f1', siuFile('pagamentos.csv', $csv));

    expect($upload['status'])->toBe(200)
        ->and($upload['url'])->toContain('purpose='.ApplySpreadsheetImportUploadRules::PURPOSE);

    $component->callMountedAction()->assertHasNoActionErrors();
    $payments = Payment::query()->where('emission_id', $emission->id)->orderBy('payment_date')->get();

    expect($payments)->toHaveCount(2)
        ->and($payments[0]->interest_value)->toBe('102110.75')
        ->and($payments[0]->amortization_value)->toBe('326085.00')
        ->and($payments[1]->premium_value)->toBe('1250.50')
        ->and($payments[1]->amortization_value)->toBe('326090.31')
        ->and($payments[1]->extra_amortization_value)->toBe('10.90')
        ->and(EmissionPuSettlement::query()->count())->toBe(0);
});

it('still imports the payment schedule spreadsheet as XLSX', function () {
    $emission = Emission::factory()->create();
    $this->actingAs(makeAdminUser());
    $component = siuPaymentsComponent($emission)->mountAction(TestAction::make('import')->table());

    $upload = siuUpload($component, 'mountedActions.0.data.file.f1', siuXlsx([['Data', 'Juros'], ['2026-06-30', '100,50']]));

    expect($upload['status'])->toBe(200);

    $component->callMountedAction();

    expect(Payment::query()->where('emission_id', $emission->id)->sole()->interest_value)->toBe('100.50');
});

it('refuses a payment CSV with a non numeric amount and keeps the schedule untouched', function () {
    $emission = Emission::factory()->create();
    $emission->payments()->create(['payment_date' => '2026-06-30', 'premium_value' => 0, 'interest_value' => 10, 'amortization_value' => 0, 'extra_amortization_value' => 0]);
    $this->actingAs(makeAdminUser());
    $component = siuPaymentsComponent($emission)->mountAction(TestAction::make('import')->table());

    siuUpload($component, 'mountedActions.0.data.file.f1', siuFile('pagamentos.csv', "Data,Juros\n2026-06-30,999\n2026-07-30,abc\n"));
    $component->callMountedAction();
    $body = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])->map(fn ($n) => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode(' ');

    expect($body)->toContain('Planilha não importada', '30/07/2026', '"abc"', 'Juros', 'Nenhum pagamento foi gravado')
        ->and(Payment::query()->where('emission_id', $emission->id)->sole()->interest_value)->toBe('10.00');
});

it('refuses a payment CSV without any recognizable row instead of reporting success', function (string $csv) {
    $emission = Emission::factory()->create();
    $this->actingAs(makeAdminUser());
    $component = siuPaymentsComponent($emission)->mountAction(TestAction::make('import')->table());

    expect(siuUpload($component, 'mountedActions.0.data.file.f1', siuFile('pagamentos.csv', $csv))['status'])->toBe(200);
    $component->callMountedAction();
    $body = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])->map(fn ($n) => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode(' ');

    expect($body)->toContain('Planilha não importada', 'Nenhuma linha de pagamento foi reconhecida')
        ->and($body)->not->toContain('Importação concluída')
        ->and(Payment::query()->count())->toBe(0);
})->with([
    'separada por ponto e vírgula' => ["Data;Juros;Amortização\n15/01/2026;1.234,56;0,00\n15/02/2026;1.100,00;500,00\n"],
    'só o cabeçalho' => ["Data,Juros\n"],
    'texto sem pagamento' => ["observação qualquer\nsem data nenhuma\n"],
]);

it('rejects files that only pretend to be CSV at the upload endpoint', function (string $name, string $contents) {
    expect(siuPost(siuImportUrl(), siuFile($name, $contents)))->toBe(422);
})->with([
    'executável Windows renomeado' => ['pagamentos.csv', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 64)],
    'ELF renomeado' => ['cdi.csv', "\x7fELF\x02\x01\x01\x00".str_repeat("\x00", 120)],
    'script PHP renomeado' => ['cdi.csv', "<?php echo 'x'; ?>\n"],
    'shell script renomeado' => ['cdi.csv', "#!/bin/sh\necho hi\n"],
    'HTML renomeado' => ['cdi.csv', "<!doctype html><html><body><script>alert(1)</script></body></html>\n"],
    'PDF renomeado' => ['cdi.csv', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n"],
    'CSV vazio' => ['cdi.csv', ''],
    'texto com outra extensão' => ['cdi.txt', "rate_date,rate_value\n2026-03-16,14.90\n"],
    'PHP com extensão .php' => ['cdi.php', "rate_date,rate_value\n2026-03-16,14.90\n"],
]);

it('tells the user which file was refused, without the internal state path', function () {
    $this->actingAs(siuUser([AccessPermission::PuIndexImport]));
    $component = Livewire::test(ListIndexRates::class)->mountAction('importPublished');
    $name = 'mountedActions.0.data.csv_file.f1';

    $upload = siuUpload($component, $name, siuFile('cdi.csv', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 64)));
    $component->call('_uploadErrored', $name, json_encode(['errors' => $upload['errors']]), false);
    $message = (string) collect($component->errors()->get($name))->first();

    expect($upload['status'])->toBe(422)
        ->and($message)->toContain('arquivo', 'csv, txt, xlsx')
        ->and($message)->not->toContain('mountedActions');
});

it('rejects a CSV above the import size limit', function () {
    $oversized = str_repeat("2026-03-16,14.90000000\n", (int) ceil((10240 * 1024 + 1) / 23));

    expect(siuPost(siuImportUrl(), siuFile('cdi.csv', $oversized)))->toBe(422)
        ->and(siuPost(siuImportUrl(), siuFile('cdi.csv', "rate_date,rate_value\n2026-03-16,14.90\n")))->toBe(200);
});

it('keeps the global temporary upload rule closed to CSV and open to the formats other modules use', function () {
    $globalUrl = GenerateSignedUploadUrlFacade::forLocal();

    expect(siuPost($globalUrl, siuFile('cdi.csv', "rate_date,rate_value\n2026-03-16,14.90\n2026-03-17,14.90\n")))->toBe(422)
        ->and(siuPost($globalUrl, siuFile('cdi.txt', "rate_date,rate_value\n")))->toBe(422)
        ->and(siuPost($globalUrl, siuFile('nota.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n")))->toBe(200)
        ->and(siuPost($globalUrl, UploadedFile::fake()->image('foto.jpg')))->toBe(200)
        ->and(siuPost($globalUrl, UploadedFile::fake()->image('logo.png')))->toBe(200)
        ->and(siuPost($globalUrl, siuXlsx([['Data', 'Juros'], ['2026-06-30', '1']])))->toBe(200)
        ->and(config('livewire.temporary_file_upload.rules'))->toBe(['required', 'file', 'max:102400', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx']);
});

it('does not let the browser ask for the import profile without a signature that carries it', function () {
    $globalUrl = GenerateSignedUploadUrlFacade::forLocal();
    $csv = fn (): UploadedFile => siuFile('cdi.csv', "rate_date,rate_value\n2026-03-16,14.90\n2026-03-17,14.90\n");

    expect(siuPost($globalUrl.'&purpose='.ApplySpreadsheetImportUploadRules::PURPOSE, $csv()))->toBe(401)
        ->and(siuPost(route('livewire.upload-file', ['purpose' => ApplySpreadsheetImportUploadRules::PURPOSE]), $csv()))->toBe(401)
        ->and(siuPost(str_replace('purpose='.ApplySpreadsheetImportUploadRules::PURPOSE, 'purpose=x', siuImportUrl()), $csv()))->toBe(401)
        ->and(config('livewire.temporary_file_upload.rules'))->not->toContain('mimes:csv,txt,xlsx');
});

it('grants the import profile only to the file field of a visible import action', function () {
    $this->actingAs(siuUser([AccessPermission::PuIndexImport]));
    $urlFor = function (Testable $component, string $name): string {
        $component->call('_startUpload', $name, [['name' => 'x.csv', 'size' => 10, 'type' => 'text/csv']], false);

        return (string) collect(data_get($component->effects, 'dispatches'))->firstWhere('name', 'upload:generatedSignedUrl')['params']['url'];
    };

    $mounted = Livewire::test(ListIndexRates::class)->mountAction('importPublished');
    $idle = Livewire::test(ListIndexRates::class);

    expect($urlFor($mounted, 'mountedActions.0.data.csv_file.f1'))->toContain('purpose=')
        ->and($urlFor($mounted, 'mountedActions.0.data.source.f1'))->not->toContain('purpose=')
        ->and($urlFor($idle, 'mountedActions.0.data.csv_file.f1'))->not->toContain('purpose=');

    // Sem a permissão, a ação não monta; forjar o estado público não concede o perfil.
    $this->actingAs(siuUser([AccessPermission::PuDashboardView]));
    $forged = Livewire::test(ListIndexRates::class)->set('mountedActions', [['name' => 'importPublished', 'arguments' => [], 'context' => [], 'data' => []]]);

    expect($urlFor($forged, 'mountedActions.0.data.csv_file.f1'))->not->toContain('purpose=');
});

it('hides the index CSV imports from whoever lacks pu.index.import', function () {
    $this->actingAs(siuUser([AccessPermission::PuDashboardView, AccessPermission::PuIndexSync]));

    Livewire::test(ListIndexRates::class)
        ->assertActionHidden('importPublished')
        ->assertActionHidden('importProjectedSeries');
});

it('keeps the payment import away from users without emissions.update, on the edit and on the view page', function () {
    $emission = Emission::factory()->create();

    // Quem edita a emissão importa também pela página de visualização (decisão
    // fixada em EmissionPaymentScheduleRemovalTest); quem só visualiza, em nenhuma.
    $this->actingAs(makeAdminUser());
    siuPaymentsComponent($emission)->assertActionVisible(TestAction::make('import')->table());
    siuPaymentsComponent($emission, ViewEmission::class)->assertActionVisible(TestAction::make('import')->table());

    $this->actingAs(siuUser([AccessPermission::EmissionsView]));
    siuPaymentsComponent($emission)->assertActionHidden(TestAction::make('import')->table());

    $viewer = siuPaymentsComponent($emission, ViewEmission::class);
    $viewer->call('_startUpload', 'mountedActions.0.data.file.f1', [['name' => 'x.csv', 'size' => 10, 'type' => 'text/csv']], false);

    expect((string) collect(data_get($viewer->effects, 'dispatches'))->firstWhere('name', 'upload:generatedSignedUrl')['params']['url'])->not->toContain('purpose=');

    $viewer->mountAction(TestAction::make('import')->table())->callMountedAction();

    expect(Payment::query()->count())->toBe(0);
});
