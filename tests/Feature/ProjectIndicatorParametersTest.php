<?php

use App\Actions\Proposals\CalculateProjectIndicators;
use App\Actions\Proposals\StoreProjectIndicatorParameters;
use App\Enums\ProjectIndicatorClassification;
use App\Filament\Resources\Proposals\Pages\EditProposal;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\RelationManagers\ProjectRelationManager;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalProject;
use App\Models\ProposalRepresentative;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('stores partial per-project parameters without replacing blanks with zero and audits changes', function () {
    [$proposal, $project] = indicatorProjectContext();
    $admin = makeAdminUser();
    $this->actingAs($admin);

    $action = app(StoreProjectIndicatorParameters::class);
    $parameters = $action->handle($project, [
        'financiamento_custo_obra_ideal' => '70,00',
        'financiamento_custo_obra_limite' => '90,00',
        'financiamento_vgv_ideal' => '0,00',
        'financiamento_vgv_limite' => '',
    ]);

    expect((float) $parameters->financiamento_custo_obra_ideal)->toBe(70.0)
        ->and((float) $parameters->financiamento_custo_obra_limite)->toBe(90.0)
        ->and((float) $parameters->financiamento_vgv_ideal)->toBe(0.0)
        ->and($parameters->financiamento_vgv_limite)->toBeNull()
        ->and($parameters->terreno_vgv_ideal)->toBeNull();

    $action->handle($project, [
        'financiamento_custo_obra_ideal' => 75,
        'financiamento_custo_obra_limite' => 95,
        'financiamento_vgv_ideal' => 0,
    ]);

    $activity = Activity::query()
        ->where('subject_type', $parameters::class)
        ->where('subject_id', $parameters->id)
        ->where('event', 'updated')
        ->latest()
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id)
        ->and((float) $activity->properties['old']['financiamento_custo_obra_ideal'])->toBe(70.0)
        ->and((float) $activity->properties['attributes']['financiamento_custo_obra_ideal'])->toBe(75.0)
        ->and($proposal->projects()->findOrFail($project->id)->indicators()->count())->toBe(1);
});

it('rejects incoherent limits for both fixed directions with clear field errors', function () {
    [, $project] = indicatorProjectContext();
    $action = app(StoreProjectIndicatorParameters::class);

    try {
        $action->handle($project, [
            'financiamento_vgv_ideal' => 45,
            'financiamento_vgv_limite' => 35,
        ]);

        $this->fail('A validação deveria rejeitar o limite inferior ao ideal.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('financiamento_vgv_limite')
            ->and($exception->errors()['financiamento_vgv_limite'][0])->toBe('Para este indicador, o Limite deve ser maior ou igual ao Ideal.');
    }

    try {
        $action->handle($project, [
            'recebiveis_vfcto_ideal' => 60,
            'recebiveis_vfcto_limite' => 80,
        ]);

        $this->fail('A validação deveria rejeitar o limite superior ao ideal.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('recebiveis_vfcto_limite')
            ->and($exception->errors()['recebiveis_vfcto_limite'][0])->toBe('Para este indicador, o Limite deve ser menor ou igual ao Ideal.');
    }
});

it('keeps parameters isolated between projects in the same proposal', function () {
    [$proposal, $firstProject] = indicatorProjectContext();
    $secondProject = $firstProject->replicate();
    $secondProject->name = 'Torre Indicadores II';
    $secondProject->proposal_id = $proposal->id;
    $secondProject->save();

    $action = app(StoreProjectIndicatorParameters::class);
    $action->handle($firstProject, [
        'financiamento_vgv_ideal' => 30,
        'financiamento_vgv_limite' => 40,
    ]);
    $action->handle($secondProject, [
        'financiamento_vgv_ideal' => 35,
        'financiamento_vgv_limite' => 45,
    ]);

    expect((float) $firstProject->fresh()->indicators->financiamento_vgv_ideal)->toBe(30.0)
        ->and((float) $firstProject->fresh()->indicators->financiamento_vgv_limite)->toBe(40.0)
        ->and((float) $secondProject->fresh()->indicators->financiamento_vgv_ideal)->toBe(35.0)
        ->and((float) $secondProject->fresh()->indicators->financiamento_vgv_limite)->toBe(45.0);
});

it('recalculates values and classifications after project data changes while preserving parameters', function () {
    [, $project] = indicatorProjectContext();
    app(StoreProjectIndicatorParameters::class)->handle($project, [
        'financiamento_custo_obra_ideal' => 70,
        'financiamento_custo_obra_limite' => 90,
    ]);

    $before = collect(app(CalculateProjectIndicators::class)->handle($project->fresh())['indicators'])
        ->firstWhere('key', 'financiamento_custo_obra');

    $project->update(['requested_amount' => 100]);
    $project->refresh()->load('indicators');

    $after = collect(app(CalculateProjectIndicators::class)->handle($project)['indicators'])
        ->firstWhere('key', 'financiamento_custo_obra');

    expect($before['value'])->toBe(70.0)
        ->and($before['classification'])->toBe(ProjectIndicatorClassification::Enquadrado->label())
        ->and($after['value'])->toBe(100.0)
        ->and($after['classification'])->toBe(ProjectIndicatorClassification::Desenquadrado->label())
        ->and((float) $project->indicators->financiamento_custo_obra_ideal)->toBe(70.0)
        ->and((float) $project->indicators->financiamento_custo_obra_limite)->toBe(90.0);
});

it('lets an authorized analyst configure all indicators in one project action', function () {
    [$proposal, $project] = indicatorProjectContext();
    $admin = makeAdminUser();

    Livewire::actingAs($admin)
        ->test(ProjectRelationManager::class, [
            'ownerRecord' => $proposal,
            'pageClass' => EditProposal::class,
        ])
        ->assertTableActionVisible('configureIndicators', $project)
        ->assertTableActionHasLabel('configureIndicators', 'Definir parâmetros')
        ->callTableAction('configureIndicators', $project, data: [
            'financiamento_custo_obra_ideal' => 70,
            'financiamento_custo_obra_limite' => 90,
            'recebiveis_vfcto_ideal' => 100,
            'recebiveis_vfcto_limite' => 60,
        ])
        ->assertHasNoTableActionErrors();

    $parameters = $project->fresh()->indicators;

    expect((float) $parameters->financiamento_custo_obra_ideal)->toBe(70.0)
        ->and((float) $parameters->recebiveis_vfcto_limite)->toBe(60.0)
        ->and($parameters->ltv_ideal)->toBeNull();
});

it('shows coherence validation errors inside the batch configuration action', function () {
    [$proposal, $project] = indicatorProjectContext();
    $admin = makeAdminUser();

    Livewire::actingAs($admin)
        ->test(ProjectRelationManager::class, [
            'ownerRecord' => $proposal,
            'pageClass' => EditProposal::class,
        ])
        ->callTableAction('configureIndicators', $project, data: [
            'financiamento_vgv_ideal' => 45,
            'financiamento_vgv_limite' => 35,
        ])
        ->assertHasTableActionErrors(['financiamento_vgv_limite']);

    expect($project->indicators()->exists())->toBeFalse();
});

it('shows indicator consultation but not configuration to a view-only assigned user', function () {
    [$proposal, $project] = indicatorProjectContext();
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('proposals.view');
    $representative = ProposalRepresentative::factory()->create(['user_id' => $viewer->id]);
    $proposal->forceFill(['assigned_representative_id' => $representative->id])->save();

    Livewire::actingAs($viewer)
        ->test(ProjectRelationManager::class, [
            'ownerRecord' => $proposal,
            'pageClass' => ViewProposal::class,
        ])
        ->assertTableActionHidden('configureIndicators', $project)
        ->assertTableActionVisible('viewIndicators', $project)
        ->assertTableActionHasLabel('viewIndicators', 'Visualizar indicadores');
});

/** @return array{Proposal, ProposalProject} */
function indicatorProjectContext(): array
{
    $company = ProposalCompany::query()->create([
        'name' => 'Construtora Indicadores',
        'cnpj' => validTestCnpj(fake()->unique()->numberBetween(1000, 9000)),
    ]);
    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => 'Analista de Indicadores',
        'email' => fake()->unique()->safeEmail(),
    ]);
    $proposal = Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'status' => 'em_analise',
    ]);
    $project = $proposal->projects()->create([
        'name' => 'Torre Indicadores',
        'requested_amount' => 70,
        'land_market_value' => 20,
        'exchanged_units' => 10,
        'paid_units' => 20,
        'unpaid_units' => 10,
        'stock_units' => 60,
        'incurred_cost' => 30,
        'cost_to_incur' => 70,
        'paid_sales_value' => 20,
        'unpaid_sales_value' => 80,
        'stock_sales_value' => 100,
        'received_value' => 20,
        'value_until_keys' => 30,
        'value_after_keys' => 50,
    ]);

    return [$proposal, $project];
}
