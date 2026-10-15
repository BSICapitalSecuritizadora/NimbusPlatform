<?php

use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexSyncOutcome;
use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Enums\AccessPermission;
use App\Filament\Resources\IndexRates\Pages\ListIndexRates;
use App\Filament\Widgets\IndexRates\PuIndexSyncAttemptsWidget;
use App\Models\IndexRate;
use App\Models\IndexRateCorrection;
use App\Models\PuIndexSyncAttempt;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 8 (UI-5): correção governada de índice, revisão de valores retidos e
 * histórico de sincronização. A tela nunca grava índice direto: corrige pelo
 * serviço (com autoridade própria e motivo) e confirma retido só por importação
 * explícita.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  list<AccessPermission>  $permissions
 */
function p8xUser(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_map(fn (AccessPermission $permission): string => $permission->value, [AccessPermission::PuIndexImport, ...$permissions]));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function p8xCdi(string $date): IndexRate
{
    return IndexRate::query()->where('indexer', 'CDI')->whereDate('rate_date', $date)->sole();
}

it('keeps the historical correction away from whoever only imports or syncs indexes', function () {
    Fx::emission();
    $rate = p8xCdi('2026-03-03');
    $this->actingAs(p8xUser([AccessPermission::PuIndexSync]));

    Livewire::test(ListIndexRates::class)
        ->assertActionHidden(TestAction::make('correctObservation')->table($rate))
        ->call('mountAction', 'correctObservation', [], ['table' => true, 'recordKey' => (string) $rate->getKey()])
        ->assertSet('mountedActions', []);

    expect(IndexRateCorrection::query()->count())->toBe(0)
        ->and(fn () => app(IndexRateCorrectionService::class)->correctAs(auth()->user(), PuIndexer::Cdi, '2026-03-03', '15.00000000', 'Sem autoridade.'))
        ->toThrow(AuthorizationException::class, 'pu.index.correct');
});

it('corrects a CDI observation through the governed service and marks the official curve for reprocessing', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $official = Fx::official($emission);
    $rate = p8xCdi('2026-03-03');
    $user = p8xUser([AccessPermission::PuIndexCorrect]);
    $this->actingAs($user);

    Livewire::test(ListIndexRates::class)
        ->mountAction(TestAction::make('correctObservation')->table($rate))
        ->assertMountedActionModalSee(['Curvas que usaram esta observação', $official->calculation_version, 'reprocessamento necessário'])
        ->setActionData(['new_value' => '15.10000000', 'reason' => '', 'confirm' => false])
        ->callMountedAction()
        ->assertHasActionErrors(['reason', 'confirm'])
        ->setActionData(['reason' => 'Revisão publicada pelo BCB.', 'confirm' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Observação corrigida.');

    $correction = IndexRateCorrection::query()->sole();

    expect($correction->corrected_by)->toBe($user->id)
        ->and((string) $correction->previous_rate_value)->toBe('14.90000000')
        ->and((string) $correction->new_rate_value)->toBe('15.10000000')
        ->and($correction->reason)->toBe('Revisão publicada pelo BCB.')
        ->and((string) $rate->fresh()->rate_value)->toBe('15.10000000')
        ->and($official->fresh()->extension_diverged_at)->not->toBeNull()
        ->and($official->fresh()->status->isOfficial())->toBeTrue();
});

it('refuses a correction prepared on a value that changed meanwhile', function () {
    Fx::emission();
    $rate = p8xCdi('2026-03-03');
    $this->actingAs(p8xUser([AccessPermission::PuIndexCorrect]));

    $component = Livewire::test(ListIndexRates::class)->mountAction(TestAction::make('correctObservation')->table($rate));
    app(IndexRateCorrectionService::class)->correct(PuIndexer::Cdi, '2026-03-03', '15.00000000', 'Outro operador.', User::factory()->create()->id);

    $component->setActionData(['new_value' => '15.20000000', 'reason' => 'Correção velha.', 'confirm' => true])
        ->callMountedAction()
        ->assertNotified('A observação mudou enquanto o formulário estava aberto.');

    expect(IndexRateCorrection::query()->count())->toBe(1)
        ->and((string) $rate->fresh()->rate_value)->toBe('15.00000000');
});

it('does not offer correction of a projected row', function () {
    $projected = IndexRate::query()->create([
        'indexer' => 'IPCA',
        'rate_date' => '2027-01-01',
        'rate_value' => '0.40000000',
        'source' => 'anbima',
        'is_projected' => true,
    ]);
    $this->actingAs(p8xUser([AccessPermission::PuIndexCorrect]));

    Livewire::test(ListIndexRates::class)->assertActionHidden(TestAction::make('correctObservation')->table($projected));
});

it('retains an implausible imported value until the operator explicitly confirms it', function () {
    Storage::fake('local');
    // A regra global de upload do Livewire hoje recusa CSV (dívida registrada fora
    // do PU): o teste libera CSV só aqui para exercitar a lógica da importação.
    config()->set('livewire.temporary_file_upload.rules', ['required', 'file', 'max:102400', 'mimes:csv,txt']);
    Fx::emission();
    $this->actingAs(p8xUser([]));
    $csv = "rate_date,rate_value\n2026-03-16,149.00\n";

    Livewire::test(ListIndexRates::class)
        ->callAction('importPublished', data: [
            'indexer' => PuIndexer::Cdi->value,
            'source' => 'manual_import',
            'csv_file' => UploadedFile::fake()->createWithContent('cdi.csv', $csv),
            'confirm_outliers' => false,
        ])
        ->assertHasNoActionErrors();

    $body = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])->map(fn ($n) => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode(' ');

    expect($body)->toContain('RETIDO', '16/03/2026 = 149')
        ->and(IndexRate::query()->whereDate('rate_date', '2026-03-16')->exists())->toBeFalse();

    Livewire::test(ListIndexRates::class)
        ->mountAction('observationReview')
        ->assertMountedActionModalSee(['Retido para confirmação', '16/03/2026']);

    Livewire::test(ListIndexRates::class)
        ->callAction('importPublished', data: [
            'indexer' => PuIndexer::Cdi->value,
            'source' => 'manual_import',
            'csv_file' => UploadedFile::fake()->createWithContent('cdi.csv', $csv),
            'confirm_outliers' => true,
        ]);

    expect((string) IndexRate::query()->whereDate('rate_date', '2026-03-16')->sole()->rate_value)->toBe('149.00000000');
});

it('separates a source that failed from one that simply had nothing new yet', function () {
    $quiet = PuIndexSyncAttempt::query()->create([
        'indexer' => 'CDI', 'source' => 'bcb_sgs', 'requested_from' => '2026-03-10', 'requested_to' => '2026-03-16',
        'started_at' => now(), 'finished_at' => now(), 'outcome' => PuIndexSyncOutcome::NoNewObservation->value,
    ]);
    $failed = PuIndexSyncAttempt::query()->create([
        'indexer' => 'CDI', 'source' => 'bcb_sgs', 'requested_from' => '2026-03-10', 'requested_to' => '2026-03-16',
        'started_at' => now(), 'finished_at' => now(), 'outcome' => PuIndexSyncOutcome::Failed->value,
        'failure_category' => PuOperationalFailureCategory::ProviderUnavailable->value, 'error_message' => 'HTTP 503',
    ]);
    $this->actingAs(p8xUser([]));

    Livewire::test(PuIndexSyncAttemptsWidget::class)
        ->assertCanSeeTableRecords([$quiet, $failed])
        ->assertTableColumnFormattedStateSet('outcome', PuIndexSyncOutcome::NoNewObservation->label(), $quiet)
        ->assertTableColumnFormattedStateSet('outcome', PuIndexSyncOutcome::Failed->label(), $failed)
        ->assertSee(['Fonte externa indisponível', 'antes do horário de divulgação']);
});

it('grants the index correction authority to administrators only', function () {
    $permission = Permission::query()->where('name', AccessPermission::PuIndexCorrect->value)->first();
    $permission?->roles()->detach();
    $permission?->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    (require database_path('migrations/2026_10_14_113055_grant_pu_index_correct_permission.php'))->up();

    expect(Role::findByName('admin')->hasPermissionTo(AccessPermission::PuIndexCorrect->value))->toBeTrue()
        ->and(Role::findByName('super-admin')->hasPermissionTo(AccessPermission::PuIndexCorrect->value))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo(AccessPermission::PuIndexCorrect->value))->toBeFalse();
});
