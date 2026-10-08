<?php

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementFileValidationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ForgedLivewireRequest;
use Tests\Support\MeasurementPlanVersionFixture;

uses(RefreshDatabase::class);

const PATH_TAMPERING_MESSAGE = 'O arquivo informado não pertence a esta medição. Envie o arquivo novamente.';

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    config()->set('filesystems.private_disk', 'local');
});

function pathTamperingEditor(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');

    return $user;
}

/**
 * Grava um PDF no disco privado num caminho que só quem já o viu conhece.
 */
function pathTamperingStore(string $bytes, ?string $path = null): string
{
    $path ??= 'nimbus_docs/measurements/assets/'.Str::uuid().'/'.Str::random(40).'.pdf';
    Storage::disk('local')->put($path, $bytes);

    return $path;
}

/**
 * A Operação A, da qual o editor participa, tem uma medição aberta com um
 * arquivo por empreendimento e uma competência livre; a Operação B é de outra
 * pessoa e guarda o arquivo que o editor não pode baixar.
 *
 * @return array{attacker: User, operation: Operation, measurement: Measurement, assets: list<MeasurementAsset>, ownBytes: list<string>, freeLines: list<MeasurementPlanLine>, victimAsset: MeasurementAsset, victimPath: string, victimBytes: string}
 */
function pathTamperingScenario(int $developments = 1): array
{
    $attacker = pathTamperingEditor();
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create([
        'status' => 'active',
        'assigned_user_id' => $attacker->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-09-01',
        'status' => 'pending',
        'current_stage' => 1,
        'storage_path' => null,
        'filename' => null,
    ]);
    $assets = [];
    $ownBytes = [];
    $freeLines = [];

    foreach (range(1, $developments) as $development) {
        $construction = Construction::factory()->create(['emission_id' => $emission->id]);
        $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $construction->id]);
        $line = MeasurementPlanLine::factory()->create([
            'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 1, 'measurement_date' => '2026-09-01',
        ]);
        $freeLines[] = MeasurementPlanLine::factory()->create([
            'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 2, 'measurement_date' => '2026-10-01',
        ]);
        // O cronograma entra no rascunho da V1, e o plano só recebe medição
        // depois de vigente: a versão é ativada antes do envio.
        MeasurementPlanVersionFixture::activate($planSet);
        $ownBytes[] = "%PDF-1.7 arquivo legítimo {$development} da operação A";
        $assets[] = $measurement->assets()->create([
            'plan_set_id' => $planSet->id,
            'plan_line_id' => $line->id,
            'storage_path' => pathTamperingStore(end($ownBytes)),
            'storage_disk' => 'local',
            'filename' => "medicao-{$development}.pdf",
        ]);
    }

    $victimOperation = Operation::factory()->create(['assigned_user_id' => pathTamperingEditor()->id]);
    $victimMeasurement = Measurement::factory()->create([
        'operation_id' => $victimOperation->id,
        'storage_path' => null,
        'filename' => null,
    ]);
    $victimBytes = '%PDF-1.7 segredo da operação B';
    $victimPath = pathTamperingStore($victimBytes);
    $victimAsset = $victimMeasurement->assets()->create([
        'storage_path' => $victimPath,
        'storage_disk' => 'local',
        'filename' => 'medicao-b.pdf',
    ]);

    return compact('attacker', 'operation', 'measurement', 'assets', 'ownBytes', 'freeLines', 'victimAsset', 'victimPath', 'victimBytes');
}

function pathTamperingDownloadedBody(TestResponse $response): string
{
    $base = $response->baseResponse;

    if ($base instanceof BinaryFileResponse) {
        return (string) file_get_contents($base->getFile()->getPathname());
    }

    if ($base instanceof StreamedResponse) {
        return (string) $response->streamedContent();
    }

    return (string) $response->getContent();
}

it('keeps the file of another operation out of reach of the editor', function () {
    $scenario = pathTamperingScenario();

    $this->actingAs($scenario['attacker'])
        ->get(route('admin.measurements.assets.download', $scenario['victimAsset']))
        ->assertForbidden();
});

it('refuses a forged asset path when editing a measurement', function (string $target) {
    $scenario = pathTamperingScenario(developments: 2);
    [$first, $second] = $scenario['assets'];
    $targetPath = match ($target) {
        'file of another operation' => $scenario['victimPath'],
        'file of another module on the same disk' => pathTamperingStore('%PDF-1.7 documento de outro módulo', 'documents/'.Str::ulid().'.pdf'),
        'unreferenced file in the measurement area' => pathTamperingStore('%PDF-1.7 versão anterior preservada'),
        'file of another development of the same measurement' => $second->storage_path,
    };
    $before = $first->fresh()->getRawOriginal();
    $this->actingAs($scenario['attacker']);

    $page = Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->set("data.assets.record-{$first->id}.storage_path", [(string) Str::uuid() => $targetPath])
        ->call('save');

    expect($page->errors()->get("data.assets.record-{$first->id}.storage_path"))->toBe([PATH_TAMPERING_MESSAGE])
        ->and($first->fresh()->getRawOriginal())->toBe($before)
        ->and(Activity::query()
            ->where('log_name', 'measurement_assets')
            ->where('description', 'measurement_asset_replaced')
            ->where('subject_id', $first->id)
            ->exists())->toBeFalse()
        ->and(pathTamperingDownloadedBody($this->get(route('admin.measurements.assets.download', $first))))->toBe($scenario['ownBytes'][0]);
    Storage::disk('local')->assertExists($targetPath);
})->with([
    'file of another operation',
    'file of another module on the same disk',
    'unreferenced file in the measurement area',
    'file of another development of the same measurement',
]);

it('keeps an unreferenced measurement file when a forged path rides along a rejected upload', function () {
    $scenario = pathTamperingScenario(developments: 2);
    [$first, $second] = $scenario['assets'];
    $orphan = pathTamperingStore('%PDF-1.7 versão anterior preservada');
    $before = [$first->fresh()->storage_path, $second->fresh()->storage_path];
    $this->actingAs($scenario['attacker']);

    $page = Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->set("data.assets.record-{$first->id}.storage_path", [(string) Str::uuid() => $orphan])
        ->fillForm(['assets' => ["record-{$second->id}" => [
            'plan_set_id' => $second->plan_set_id,
            'plan_line_id' => $second->plan_line_id,
            'storage_path' => [UploadedFile::fake()->createWithContent('invalido.pdf', 'isto não é um pdf')],
        ]]]);

    rescue(fn () => $page->call('save'), report: false);

    Storage::disk('local')->assertExists($orphan);
    expect([$first->fresh()->storage_path, $second->fresh()->storage_path])->toBe($before);
});

it('refuses a forged asset path when sending a new measurement', function () {
    $scenario = pathTamperingScenario();
    $this->actingAs($scenario['attacker']);

    $page = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $scenario['operation']->id]);
    $key = array_key_first($page->get('data.assets'));
    $page->set("data.assets.{$key}.plan_line_id", $scenario['freeLines'][0]->id)
        ->set('data.reference_month', '2026-10-01')
        ->set("data.assets.{$key}.storage_path", [(string) Str::uuid() => $scenario['victimPath']])
        ->call('create');

    expect($page->errors()->get("data.assets.{$key}.storage_path"))->toBe([PATH_TAMPERING_MESSAGE])
        ->and($scenario['operation']->measurements()->count())->toBe(1)
        ->and(MeasurementAsset::query()->where('storage_path', $scenario['victimPath'])->count())->toBe(1);
    Notification::assertNothingSent();
    Storage::disk('local')->assertExists($scenario['victimPath']);
});

it('refuses the forged path through the real Livewire endpoint', function () {
    $scenario = pathTamperingScenario();
    [$asset] = $scenario['assets'];
    $ownPath = $asset->storage_path;
    $this->actingAs($scenario['attacker']);

    $html = $this->get(MeasurementResource::getUrl('edit', ['record' => $scenario['measurement']]))
        ->assertOk()
        ->getContent();
    $snapshot = ForgedLivewireRequest::snapshotOf($html, EditMeasurement::class);

    ForgedLivewireRequest::post($this, $snapshot, [ForgedLivewireRequest::call('save')], [
        "data.assets.record-{$asset->id}.storage_path" => [(string) Str::uuid() => $scenario['victimPath']],
    ])->assertOk();

    expect($asset->fresh()->storage_path)->toBe($ownPath)
        ->and(pathTamperingDownloadedBody($this->get(route('admin.measurements.assets.download', $asset))))->toBe($scenario['ownBytes'][0]);
});

it('saves an untouched measurement without tampering errors', function () {
    $scenario = pathTamperingScenario(developments: 2);
    $this->actingAs($scenario['attacker']);

    Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(array_map(fn (MeasurementAsset $asset): string => $asset->fresh()->storage_path, $scenario['assets']))
        ->toBe(array_map(fn (MeasurementAsset $asset): string => $asset->storage_path, $scenario['assets']));
});

it('replaces the asset with a fresh upload', function () {
    $scenario = pathTamperingScenario();
    [$asset] = $scenario['assets'];
    $ownPath = $asset->storage_path;
    $this->actingAs($scenario['attacker']);

    Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->fillForm(['assets' => ["record-{$asset->id}" => [
            'plan_set_id' => $asset->plan_set_id,
            'plan_line_id' => $asset->plan_line_id,
            'storage_path' => [UploadedFile::fake()->createWithContent('nova.pdf', '%PDF-1.7 nova versão')],
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $asset->refresh();

    expect($asset->storage_path)->not->toBe($ownPath)
        ->and($asset->storage_path)->toStartWith('nimbus_docs/measurements/assets/')
        ->and($asset->sha256)->toBe(hash('sha256', '%PDF-1.7 nova versão'))
        ->and(Storage::disk('local')->get($asset->storage_path))->toBe('%PDF-1.7 nova versão');
});

it('sends a new measurement with a fresh upload', function () {
    $scenario = pathTamperingScenario();
    $this->actingAs($scenario['attacker']);

    $page = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $scenario['operation']->id]);
    $key = array_key_first($page->get('data.assets'));
    $page->fillForm([
        'reference_month' => '2026-10-01',
        'assets' => [$key => [
            'plan_set_id' => $scenario['freeLines'][0]->plan_set_id,
            'plan_line_id' => $scenario['freeLines'][0]->id,
            'storage_path' => [UploadedFile::fake()->createWithContent('medicao.pdf', '%PDF-1.7 envio legítimo')],
        ]],
    ])->call('create')
        ->assertHasNoFormErrors();

    $sent = $scenario['operation']->measurements()->latest('id')->firstOrFail();

    expect($sent->id)->not->toBe($scenario['measurement']->id)
        ->and(Storage::disk('local')->get($sent->assets()->sole()->storage_path))->toBe('%PDF-1.7 envio legítimo');
});

/**
 * O campo de upload do Editar entrega ao navegador o endereço de cada arquivo
 * já gravado. Pelo disco, seria um link SAS transferível (disco privado no
 * Azure) ou a URL pública (legado): download sem a autorização da medição, sem
 * `measurement_file_access` e sem a CSP de download. O único endereço aceito é
 * a rota autorizada do próprio arquivo.
 */
it('gives the saved file of the edit form only the authorized download route', function (string $storage) {
    $temporaryUrls = 0;
    Storage::disk('local')->buildTemporaryUrlsUsing(function (string $path) use (&$temporaryUrls): string {
        $temporaryUrls++;

        return "https://blob.example.net/container/{$path}?sig=SAS";
    });
    $scenario = pathTamperingScenario();
    [$asset] = $scenario['assets'];

    if ($storage === 'arquivo legado no disco público') {
        Storage::fake('public');
        $legacyPath = 'measurements/'.Str::random(40).'.pdf';
        Storage::disk('public')->put($legacyPath, '%PDF-1.7 arquivo legado público');
        DB::table('measurement_assets')->where('id', $asset->id)->update(['storage_path' => $legacyPath, 'storage_disk' => null]);
    }

    $this->actingAs($scenario['attacker']);
    $upload = Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->instance()
        ->getSchema('form')
        ->getComponentByStatePath("assets.record-{$asset->id}.storage_path");

    expect($upload)->toBeInstanceOf(FileUpload::class)
        ->and(array_column(array_values($upload->getUploadedFiles() ?? []), 'url'))->toBe([route('admin.measurements.assets.download', $asset)])
        ->and($temporaryUrls)->toBe(0);
})->with(['disco privado com URL temporária', 'arquivo legado no disco público']);

it('gives no address to a file that is not the saved file of the asset', function () {
    $scenario = pathTamperingScenario();
    [$asset] = $scenario['assets'];
    $this->actingAs($scenario['attacker']);
    $upload = Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->instance()
        ->getSchema('form')
        ->getComponentByStatePath("assets.record-{$asset->id}.storage_path");
    $justStored = app(MeasurementFileValidationService::class)
        ->storeAsset(UploadedFile::fake()->createWithContent('nova.pdf', '%PDF-1.7 nova versão ainda não salva'));
    $upload->rawState([(string) Str::uuid() => $justStored]);

    expect(app(MeasurementFileValidationService::class)->wasStoredDuringThisRequest($justStored))->toBeTrue();

    $files = array_values($upload->getUploadedFiles() ?? []);

    expect($files)->toHaveCount(1)
        ->and($files[0])->toBeArray()->toHaveKey('url')
        ->and($files[0]['url'])->toBeNull();
});

it('forgets the paths stored by a previous request', function () {
    $path = app(MeasurementFileValidationService::class)
        ->storeAsset(UploadedFile::fake()->createWithContent('registro.pdf', '%PDF-1.7 registro da requisição'));

    expect(app(MeasurementFileValidationService::class)->wasStoredDuringThisRequest($path))->toBeTrue()
        ->and(app(MeasurementFileValidationService::class)->wasStoredDuringThisRequest('nimbus_docs/measurements/assets/outro.pdf'))->toBeFalse();

    app()->forgetScopedInstances();

    expect(app(MeasurementFileValidationService::class)->wasStoredDuringThisRequest($path))->toBeFalse();
    Storage::disk('local')->assertExists($path);
});
