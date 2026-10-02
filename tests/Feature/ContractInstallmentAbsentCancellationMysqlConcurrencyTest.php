<?php

use App\Actions\ContractInstallments\AnalyzeContractInstallmentSpreadsheet;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetReading;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\ImportRun;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Support\CommittedRowsSweeper;

/**
 * O cancelamento das parcelas ausentes contra a edição manual concorrente, em
 * conexões reais.
 *
 * O Confirmar relê o arquivo inteiro numa transação só -- dezenas de segundos
 * numa carteira grande -- e, no REPEATABLE READ do MySQL, a leitura comum dentro
 * dela vê a foto do começo. O pagamento ou o cancelamento que outra tela gravava
 * nesse meio-tempo não aparecia: a parcela terminava paga e cancelada, e a data
 * manual do cancelamento era sobrescrita pela da importação. No SQLite isso não
 * se vê -- ele serializa escritores --, então só aqui um refactor que voltasse
 * à leitura sem trava apareceria.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar a foto do REPEATABLE READ contra uma edição em outra conexão.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * O cenário, o Confirmar e os processos filhos commitam fora de qualquer
 * transação de teste: a limpeza devolve o banco ao estado recém-migrado.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * Um contrato com a 001 paga -- a única que a planilha traz -- e a 002, a 003 e
 * a 004 em aberto, ausentes da planilha.
 *
 * @return array<string, int>
 */
function absentCancellationRaceScenario(): array
{
    $emission = Emission::factory()->create(['name' => 'CRI Conviva', 'status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Conviva Camboinhas']);
    $contract = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']))
        ->forClient(Client::factory()->create(['name' => 'João da Silva', 'document' => '52998224725']))
        ->create(['code' => 'CVC-00123', 'sale_value' => 850000.00]);

    $ids = [
        '001' => ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001', 'due_date' => '2026-01-10', 'expected_value' => 10000,
            'payment_date' => '2026-01-10', 'paid_value' => 10000,
        ])->id,
    ];

    foreach (['002' => '2026-02-10', '003' => '2026-03-10', '004' => '2026-04-10'] as $number => $due) {
        $ids[$number] = ContractInstallment::factory()->forContract($contract)->create([
            'number' => $number, 'due_date' => $due, 'expected_value' => 10000,
        ])->id;
    }

    return $ids;
}

function absentCancellationRaceSpreadsheet(): string
{
    $path = temporaryTestFilePath('ausentes-corrida');
    $headers = ContractInstallmentSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);
    $writer->addRow(array_combine($headers, array_pad(
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', '10000.00', '10/01/2026', '10000.00', ''],
        count($headers),
        '',
    )));
    $writer->close();

    return $path;
}

/**
 * O que a tela "Editar parcela" faz no banco, noutra conexão: transação em
 * volta de `$record->update()`, com o registro relido na requisição.
 *
 * @param  array<string, mixed>  $changes
 */
function absentCancellationRaceManualEdit(int $installmentId, array $changes): Closure
{
    return static function () use ($installmentId, $changes): bool {
        DB::transaction(function () use ($installmentId, $changes): void {
            ContractInstallment::query()->findOrFail($installmentId)->update($changes);
        });

        return true;
    };
}

/**
 * Faz as duas edições concorrentes, noutros processos e já confirmadas, logo
 * depois de a passada de gravação ler as ausências -- dentro da transação do
 * Confirmar, a última leitura antes do cancelamento.
 *
 * @param  array<string, int>  $ids
 */
function editAbsentInstallmentsDuringConfirm(array $ids): ArrayObject
{
    $probe = new ArrayObject(['fired' => false, 'plain' => null]);

    app()->bind(AnalyzeContractInstallmentSpreadsheet::class, fn (): AnalyzeContractInstallmentSpreadsheet => new class($ids, $probe) extends AnalyzeContractInstallmentSpreadsheet
    {
        /**
         * @param  array<string, int>  $ids
         */
        public function __construct(private readonly array $ids, private readonly ArrayObject $probe)
        {
            parent::__construct();
        }

        public function open(string $path, ?int $restrictToContractId = null): ContractInstallmentSpreadsheetReading
        {
            $reading = parent::open($path, $restrictToContractId);

            if ((DB::transactionLevel() === 0) || $this->probe['fired']) {
                return $reading;
            }

            $ids = $this->ids;
            $probe = $this->probe;

            return ContractInstallmentSpreadsheetReading::streaming((function () use ($reading, $ids, $probe): Generator {
                yield from $reading->rows();

                $absences = $reading->absences();
                $probe['fired'] = true;

                Concurrency::driver('process')->run([
                    absentCancellationRaceManualEdit($ids['002'], ['payment_date' => '2026-02-10', 'paid_value' => '10000.00']),
                    absentCancellationRaceManualEdit($ids['004'], ['cancellation_date' => '2026-06-30']),
                ]);

                // A mesma leitura que o cancelamento fazia antes, na transação do Confirmar.
                $probe['plain'] = DB::table('contract_installments')
                    ->whereIn('id', [$ids['002'], $ids['003'], $ids['004']])
                    ->whereNull('payment_date')
                    ->whereNull('cancellation_date')
                    ->orderBy('id')
                    ->pluck('id')
                    ->all();

                return $absences;
            })());
        }
    });

    return $probe;
}

it('cancels only the absent installments still open once locked, leaving the ones paid or cancelled meanwhile as they are', function () {
    /**
     * Permissões diretas, sem o seeder de papéis: ele reescreve
     * `role_has_permissions`, que não tem `id` e que a limpeza não sabe
     * devolver ao estado recém-migrado.
     */
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->givePermissionTo(['contract-installments.view', 'contract-installments.create', 'contract-installments.update']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->fresh());

    $ids = absentCancellationRaceScenario();

    $component = Livewire::test(ListContractInstallments::class)
        ->mountAction(TestAction::make('importContractInstallments'))
        ->fillForm(['file' => spreadsheetUpload(absentCancellationRaceSpreadsheet())])
        ->assertFormFieldVisible('cancel_absent_open')
        ->fillForm([
            'cancel_absent_open' => true,
            'absent_cancellation_date' => '2026-07-05',
            'absent_cancellation_reason' => 'Renegociação com novo cronograma de parcelas.',
        ]);

    $probe = editAbsentInstallmentsDuringConfirm($ids);

    $component->callMountedAction()->assertHasNoActionErrors();

    $run = ImportRun::query()->sole();
    $row = fn (string $number): array => (array) DB::table('contract_installments')->where('id', $ids[$number])->first(['payment_date', 'paid_value', 'cancellation_date']);
    $completed = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->firstWhere('title', 'Posição processada com sucesso.');

    // Pré-condição: as edições commitaram dentro da transação do Confirmar, que
    // pela leitura comum ainda via as três em aberto.
    expect($probe['fired'])->toBeTrue()
        ->and($probe['plain'])->toBe([$ids['002'], $ids['003'], $ids['004']]);

    expect($run->records_cancelled)->toBe(1)
        ->and($row('002'))->toBe(['payment_date' => '2026-02-10', 'paid_value' => '10000.00', 'cancellation_date' => null])
        ->and($row('004')['cancellation_date'])->toBe('2026-06-30')
        ->and($row('003')['cancellation_date'])->toBe('2026-07-05')
        // A trilha do run não atribui à importação o que outra tela gravou.
        ->and(Activity::query()->where('batch_uuid', $run->batch_uuid)->whereIn('subject_id', [$ids['002'], $ids['004']])->where('subject_type', ContractInstallment::class)->count())->toBe(0)
        ->and((string) $completed['body'])->toContain('2 parcela(s) ausente(s) não foram canceladas porque receberam pagamento ou cancelamento durante a confirmação.');
})->group('mysql');
