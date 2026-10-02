<?php

use App\Actions\ContractInstallments\AnalyzeContractInstallmentSpreadsheet;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetAnalysis;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetReading;
use App\Actions\ContractInstallments\ImportContractInstallmentsFromSpreadsheet;
use App\Exceptions\ImportConferenceOutdatedException;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\ImportRun;
use App\Support\Imports\ImportRunDraft;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * O custo da importação de parcelas, contado e não estimado.
 *
 * A ordenação quadrática e a dupla análise no Confirmar passaram pela suíte
 * porque nenhum teste contava análises nem memória. Aqui o que é determinístico
 * fica na esteira -- quantas vezes o arquivo é lido em cada requisição, o tamanho
 * do resumo, a memória da passada de gravação -- e o que depende da máquina
 * (tempo, volumes de produção) fica atrás de `NIMBUS_BENCH=1`.
 *
 * Por importação: o envio lê o arquivo uma vez, o Próximo nenhuma, o Confirmar
 * uma, gravando enquanto lê. E o Confirmar só grava o que a conferência mostrou.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Conta as leituras do arquivo: cada `open()` é uma passada.
 */
function countInstallmentPasses(): ArrayObject
{
    $passes = new ArrayObject(['opened' => 0]);

    app()->bind(AnalyzeContractInstallmentSpreadsheet::class, fn (): AnalyzeContractInstallmentSpreadsheet => new class($passes) extends AnalyzeContractInstallmentSpreadsheet
    {
        public function __construct(private readonly ArrayObject $passes)
        {
            parent::__construct();
        }

        public function open(string $path, ?int $restrictToContractId = null): ContractInstallmentSpreadsheetReading
        {
            $this->passes['opened']++;

            return parent::open($path, $restrictToContractId);
        }
    });

    return $passes;
}

/**
 * Uma emissão, um empreendimento e um contrato, com o número de parcelas pedido
 * já cadastradas (gravadas em lote, sem passar pelo model).
 *
 * @return array{construction: Construction, contract: Contract}
 */
function scaleScenario(int $installmentsOnRecord = 0): array
{
    [, $construction] = unitEmissionAndConstruction(status: 'active');

    $contract = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']))
        ->forClient(Client::factory()->create())
        ->create(['code' => 'CVC-00123', 'sale_date' => '2024-01-15', 'sale_value' => 900000.00]);

    if ($installmentsOnRecord > 0) {
        scaleSeedInstallments([(int) $contract->getKey()], $installmentsOnRecord);
    }

    return compact('construction', 'contract');
}

/**
 * @param  list<int>  $contractIds
 */
function scaleSeedInstallments(array $contractIds, int $perContract): void
{
    $now = now()->toDateTimeString();
    $firstDue = CarbonImmutable::parse('2024-02-15');
    $buffer = [];

    foreach ($contractIds as $contractId) {
        foreach (range(1, $perContract) as $number) {
            $label = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $due = $firstDue->addMonths($number - 1)->toDateString();
            $paid = $due < '2026-07-01';

            $buffer[] = [
                'contract_id' => $contractId,
                'number' => $label,
                'number_normalized' => ContractInstallment::normalizeNumberForComparison($label),
                'due_date' => $due,
                'expected_value' => '7500.00',
                'payment_date' => $paid ? $due : null,
                'paid_value' => $paid ? '7500.00' : null,
                'cancellation_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) === 500) {
                DB::table('contract_installments')->insert($buffer);
                $buffer = [];
            }
        }
    }

    if ($buffer !== []) {
        DB::table('contract_installments')->insert($buffer);
    }
}

/**
 * A planilha mensal das parcelas: as mesmas do cadastro, com uma fração delas
 * recebendo pagamento novo, mais as linhas extras pedidas.
 *
 * @param  array<int, string>  $contracts  contrato => código
 * @param  list<array<int, mixed>>  $extraRows
 */
function scaleSpreadsheet(string $construction, array $contracts, int $perContract, float $changedRatio, array $extraRows = []): string
{
    $path = temporaryTestFilePath('parcelas-escala');
    $headers = ContractInstallmentSpreadsheetColumns::headers();
    $writer = SimpleExcelWriter::create($path)->addHeader($headers);
    $firstDue = CarbonImmutable::parse('2024-02-15');
    $changeEvery = $changedRatio > 0 ? (int) round(1 / $changedRatio) : 0;
    $line = 0;

    foreach ($contracts as $code) {
        foreach (range(1, $perContract) as $number) {
            $line++;
            $dueDate = $firstDue->addMonths($number - 1);
            $paid = $dueDate->toDateString() < '2026-07-01';
            $changed = ($changeEvery > 0) && (($line % $changeEvery) === 0) && ! $paid;

            $writer->addRow(array_combine($headers, array_pad([
                'CRI Conviva',
                $construction,
                $code,
                str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                $dueDate->format('d/m/Y'),
                7500.00,
                $paid ? $dueDate->format('d/m/Y') : ($changed ? '15/09/2026' : ''),
                ($paid || $changed) ? 7500.00 : '',
                '',
            ], count($headers), '')));
        }
    }

    foreach ($extraRows as $row) {
        $writer->addRow(array_combine($headers, array_pad($row, count($headers), '')));
    }

    $writer->close();

    return $path;
}

function scaleDraft(string $path): ImportRunDraft
{
    return new ImportRunDraft(
        type: ImportRun::TYPE_CONTRACT_INSTALLMENTS,
        fileName: basename($path),
        checksum: hash_file('sha256', $path) ?: null,
        filePath: null,
        userId: null,
    );
}

it('reads the file once on upload, never on next, and once on confirm while writing', function () {
    $this->actingAs(makeAdminUser());

    scaleScenario(installmentsOnRecord: 12);
    $path = scaleSpreadsheet('Conviva Camboinhas', ['CVC-00123'], 12, 0.0, [
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '013', '15/02/2025', 7500.00, '', '', ''],
    ]);

    $passes = countInstallmentPasses();

    $component = Livewire::test(ListContractInstallments::class)
        ->mountAction(TestAction::make('importContractInstallments'));

    app()->forgetScopedInstances();

    $component->fillForm(['file' => spreadsheetUpload($path)])
        ->assertMountedActionModalSee('Novas: <b>1</b>', escape: false);

    expect($passes['opened'])->toBe(1);

    app()->forgetScopedInstances();

    $component->goToNextWizardStep()
        ->assertMountedActionModalSee('Novas: <b>1</b>', escape: false);

    expect($passes['opened'])->toBe(1);

    app()->forgetScopedInstances();

    $component->callMountedAction()->assertHasNoActionErrors();

    expect($passes['opened'])->toBe(2)
        ->and(ContractInstallment::query()->count())->toBe(13)
        ->and(ImportRun::query()->sole()->records_created)->toBe(1);
});

it('keeps the conference summary bounded on a large file', function () {
    ['contract' => $contract] = scaleScenario();

    $rows = [];

    foreach (range(1, 4700) as $number) {
        $rows[] = ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', (string) $number, '10/01/2026', 7500.00, '', '', ''];
    }

    foreach (range(1, 300) as $number) {
        $rows[] = ['CRI Conviva', 'Conviva Camboinhas', 'CVC-999', (string) $number, '10/01/2026', 7500.00, '', '', ''];
    }

    $path = temporaryTestFilePath('parcelas-resumo-limitado');
    $headers = ContractInstallmentSpreadsheetColumns::headers();
    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, array_pad($row, count($headers), '')));
    }

    $writer->close();

    $summary = app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path);
    $exported = $summary->toArray();

    expect($summary->totalLines())->toBe(5000)
        ->and($summary->newCount())->toBe(4700)
        ->and($summary->blockingCount())->toBe(300)
        ->and($summary->previewRows())->toHaveCount(ContractInstallmentSpreadsheetAnalysis::PREVIEW_LIMIT)
        ->and($summary->blockingRows())->toHaveCount(ContractInstallmentSpreadsheetAnalysis::BLOCKING_LIMIT)
        ->and(strlen(serialize($exported)))->toBeLessThan(256 * 1024)
        // Plain scalars and lists only: the summary travels through the cache
        // with class unserialization off.
        ->and(json_decode(json_encode($exported, JSON_THROW_ON_ERROR), true))->toEqual($exported)
        ->and(ContractInstallmentSpreadsheetAnalysis::fromArray($exported)->digest())->toBe($summary->digest())
        ->and($contract->id)->toBeGreaterThan(0);
});

it('keeps the write pass of a monthly file of ten thousand rows under 12 MB', function () {
    [, $construction] = unitEmissionAndConstruction(status: 'active');

    $contracts = [];

    foreach (range(1, 125) as $index) {
        $contract = Contract::factory()
            ->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => (string) (100 + $index)]))
            ->forClient(Client::factory()->create())
            ->create(['code' => 'VOL-'.$index, 'sale_date' => '2024-01-15', 'sale_value' => 900000.00]);

        $contracts[(int) $contract->getKey()] = 'VOL-'.$index;
    }

    scaleSeedInstallments(array_keys($contracts), 80);

    $path = scaleSpreadsheet('Conviva Camboinhas', $contracts, 80, 0.02);
    $digest = app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path)->digest();

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $result = app(ImportContractInstallmentsFromSpreadsheet::class)->handle($path, null, scaleDraft($path), $digest);

    $allocated = memory_get_peak_usage() - $before;

    expect($result->created + $result->updated + $result->unchanged)->toBe(10000)
        ->and($result->updated)->toBeGreaterThan(0)
        ->and($allocated)->toBeLessThan(12 * 1024 * 1024);
});

it('refuses the confirmation when an installment is edited between next and confirm, then writes on the second one', function () {
    $this->actingAs(makeAdminUser());

    ['contract' => $contract] = scaleScenario(installmentsOnRecord: 3);
    $path = scaleSpreadsheet('Conviva Camboinhas', ['CVC-00123'], 3, 0.0, [
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '004', '15/05/2024', 7500.00, '', '', ''],
    ]);

    $component = Livewire::test(ListContractInstallments::class)
        ->mountAction(TestAction::make('importContractInstallments'))
        ->fillForm(['file' => spreadsheetUpload($path)])
        ->goToNextWizardStep();

    // Someone edits one of the installments the conference read as unchanged.
    $contract->installments()->where('number', '002')->sole()->update(['expected_value' => 9000]);

    $component->callMountedAction();

    $notifications = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? []);

    expect(ContractInstallment::query()->count())->toBe(3)
        ->and(ImportRun::query()->count())->toBe(0)
        ->and($notifications->pluck('title')->all())->toContain('Conferência desatualizada.')
        ->and((string) $notifications->firstWhere('title', 'Conferência desatualizada.')['body'])
        ->toBe(ImportConferenceOutdatedException::MESSAGE);

    Notification::assertNotified('Conferência desatualizada.');

    $component->assertMountedActionModalSee('Atualizações críticas: <b>1</b>', escape: false)
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(ContractInstallment::query()->count())->toBe(4)
        ->and((float) ContractInstallment::query()->where('number', '002')->sole()->expected_value)->toBe(7500.00);
});

it('redoes an expired conference instead of writing it unseen', function () {
    $this->actingAs(makeAdminUser());

    scaleScenario();
    $path = scaleSpreadsheet('Conviva Camboinhas', ['CVC-00123'], 2, 0.0);

    $component = Livewire::test(ListContractInstallments::class)
        ->mountAction(TestAction::make('importContractInstallments'))
        ->fillForm(['file' => spreadsheetUpload($path)]);

    Cache::flush();

    $component->callMountedAction();

    $notifications = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? []);

    expect(ContractInstallment::query()->count())->toBe(0)
        ->and((string) $notifications->firstWhere('title', 'Conferência refeita.')['body'])
        ->toBe('A conferência anterior expirou e foi recalculada com a posição atual. Revise-a e confirme novamente.');

    Notification::assertNotified('Conferência refeita.');

    $component->callMountedAction()->assertHasNoActionErrors();

    expect(ContractInstallment::query()->count())->toBe(2);
});

/**
 * Um cache que lê mas recusa gravar -- o Redis em failover (READONLY) ou cheio
 * sem despejo -- é o mesmo cache indisponível da leitura que falha: o Confirmar
 * segue sem a guarda, com o aviso técnico no log. Antes, toda requisição
 * recalculava, nada ficava guardado, e o Confirmar respondia "Conferência
 * refeita." a cada clique, para sempre.
 */
it('goes ahead without the guard when the cache reads but refuses writes, instead of redoing the conference forever', function () {
    $this->actingAs(makeAdminUser());

    scaleScenario();
    $path = scaleSpreadsheet('Conviva Camboinhas', ['CVC-00123'], 2, 0.0);

    $refused = useCacheThatRefusesWrites();
    Log::spy();

    $component = Livewire::test(ListContractInstallments::class)
        ->mountAction(TestAction::make('importContractInstallments'));

    app()->forgetScopedInstances();

    $component->fillForm(['file' => spreadsheetUpload($path)])
        ->assertMountedActionModalSee('Novas: <b>2</b>', escape: false);

    app()->forgetScopedInstances();

    $component->goToNextWizardStep();

    app()->forgetScopedInstances();

    $component->callMountedAction()->assertHasNoActionErrors();

    $titles = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])->pluck('title')->all();

    expect(ContractInstallment::query()->count())->toBe(2)
        ->and(ImportRun::query()->sole()->records_created)->toBe(2)
        ->and($titles)->toContain('Posição processada com sucesso.')
        ->and($titles)->not->toContain('Conferência refeita.')
        ->and($refused['put'])->toBeGreaterThan(0);

    Log::shouldHaveReceived('warning')
        ->with('import-conference-cache-unavailable', Mockery::on(fn (array $context): bool => $context['operation'] === 'put'))
        ->atLeast()->once();
});

it('turns a violation of the installment number index during the write into an outdated conference', function () {
    ['contract' => $contract] = scaleScenario();
    $path = scaleSpreadsheet('Conviva Camboinhas', ['CVC-00123'], 2, 0.0);
    $digest = app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path)->digest();

    // Another write creates parcela 001 right after the pass classified it as new.
    $intrusion = new ArrayObject(['contract' => (int) $contract->getKey(), 'pending' => true]);

    app()->bind(AnalyzeContractInstallmentSpreadsheet::class, fn (): AnalyzeContractInstallmentSpreadsheet => new class($intrusion) extends AnalyzeContractInstallmentSpreadsheet
    {
        public function __construct(private readonly ArrayObject $intrusion)
        {
            parent::__construct();
        }

        protected function loadChunkContext(array $parsedRows): array
        {
            $context = parent::loadChunkContext($parsedRows);

            if ($this->intrusion['pending']) {
                $this->intrusion['pending'] = false;

                DB::table('contract_installments')->insert([
                    'contract_id' => $this->intrusion['contract'],
                    'number' => '001',
                    'number_normalized' => '001',
                    'due_date' => '2024-02-15',
                    'expected_value' => '7500.00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $context;
        }
    });

    try {
        app(ImportContractInstallmentsFromSpreadsheet::class)->handle($path, null, scaleDraft($path), $digest);

        $this->fail('The import should have been refused.');
    } catch (ImportConferenceOutdatedException $exception) {
        expect($exception->reason)->toBe(ImportConferenceOutdatedException::REASON_UNIQUE_INDEX)
            ->and($exception->freshSummary['digest'])->toBe($digest);
    }

    expect(ContractInstallment::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);
});

/**
 * Volumes de produção, medidos nesta máquina, fora da esteira: 57,6 mil e 172,8
 * mil linhas, carteira mensal e primeira carga, cada requisição (a conferência do
 * envio e a passada do Confirmar) abaixo de 256 MB e de 60 s.
 */
it('stays within the web limits at production volumes', function (string $volume) {
    if (! getenv('NIMBUS_BENCH')) {
        $this->markTestSkipped('Benchmark de volume: defina NIMBUS_BENCH=1.');
    }

    [$constructions, $mode] = match ($volume) {
        '57,6 mil mensal' => [1, 'mensal'],
        '57,6 mil primeira carga' => [1, 'primeira carga'],
        '172,8 mil mensal' => [3, 'mensal'],
        '172,8 mil primeira carga' => [3, 'primeira carga'],
    };

    $emission = Emission::factory()->create(['name' => 'CRI Conviva', 'status' => 'active']);
    $path = temporaryTestFilePath('parcelas-bench');
    $headers = ContractInstallmentSpreadsheetColumns::headers();
    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach (range(1, $constructions) as $index) {
        $name = 'Volume '.chr(ord('A') + $index - 1);
        $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => $name]);
        $contracts = [];

        foreach (range(1, 720) as $number) {
            $unitId = DB::table('construction_units')->insertGetId([
                'construction_id' => $construction->id, 'block' => '01', 'unit' => (string) $number,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $code = sprintf('VOL-%s-%d', chr(ord('A') + $index - 1), $number);
            $contracts[(int) DB::table('contracts')->insertGetId([
                'construction_unit_id' => $unitId, 'construction_id' => $construction->id, 'code' => $code,
                'code_normalized' => Contract::normalizeCodeForComparison($code), 'sale_date' => '2024-01-15',
                'sale_value' => '900000.00', 'status' => 'ativo', 'created_at' => now(), 'updated_at' => now(),
            ])] = $code;
        }

        if ($mode === 'mensal') {
            scaleSeedInstallments(array_keys($contracts), 80);
        }

        $firstDue = CarbonImmutable::parse('2024-02-15');
        $line = 0;

        foreach ($contracts as $code) {
            foreach (range(1, 80) as $number) {
                $line++;
                $dueDate = $firstDue->addMonths($number - 1);
                $paid = $dueDate->toDateString() < '2026-07-01';
                $changed = (($line % 50) === 0) && ! $paid;

                $writer->addRow(array_combine($headers, array_pad([
                    'CRI Conviva', $name, $code, str_pad((string) $number, 3, '0', STR_PAD_LEFT), $dueDate->format('d/m/Y'), 7500.00,
                    $paid ? $dueDate->format('d/m/Y') : ($changed ? '15/09/2026' : ''), ($paid || $changed) ? 7500.00 : '', '',
                ], count($headers), '')));
            }
        }
    }

    $writer->close();

    $measure = function (Closure $request): array {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $startedAt = hrtime(true);

        $result = $request();

        return [$result, (hrtime(true) - $startedAt) / 1e9, memory_get_peak_usage(true)];
    };

    // O limite do PHP-FPM da produção, só durante as duas requisições medidas:
    // o processo da suíte volta ao limite dele para o que vier depois.
    $suiteMemoryLimit = (string) ini_get('memory_limit');
    ini_set('memory_limit', '256M');

    try {
        [$conference, $conferenceSeconds, $conferencePeak] = $measure(fn () => app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path));
        [$result, $writeSeconds, $writePeak] = $measure(fn () => app(ImportContractInstallmentsFromSpreadsheet::class)->handle($path, null, scaleDraft($path), $conference->digest()));
    } finally {
        ini_set('memory_limit', $suiteMemoryLimit);
    }

    dump(compact('volume', 'conferenceSeconds', 'conferencePeak', 'writeSeconds', 'writePeak'));

    expect($conference->canImport())->toBeTrue()
        ->and($result->created + $result->updated + $result->unchanged)->toBe($constructions * 720 * 80)
        ->and($conferenceSeconds)->toBeLessThan(60.0)
        ->and($writeSeconds)->toBeLessThan(60.0)
        ->and($conferencePeak)->toBeLessThan(256 * 1024 * 1024)
        ->and($writePeak)->toBeLessThan(256 * 1024 * 1024);
})->with(['57,6 mil mensal', '57,6 mil primeira carga', '172,8 mil mensal', '172,8 mil primeira carga']);
