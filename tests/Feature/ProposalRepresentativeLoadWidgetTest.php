<?php

use App\Enums\ProposalStatus;
use App\Filament\Widgets\Proposals\ProposalRepresentativeLoadChartWidget;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('renders empty state when there are no active commercial representatives', function () {
    $adminUser = User::factory()->withTwoFactor()->create();
    $adminUser->assignRole('admin');

    $this->actingAs($adminUser);

    Livewire::test(ProposalRepresentativeLoadChartWidget::class)
        ->assertSee('Carga Operacional da Fila Comercial')
        ->assertSee('Processos em andamento distribuídos por representante comercial.')
        ->assertSee('Gerenciar Fila')
        ->assertSee('Nenhum representante comercial cadastrado ou ativo.')
        ->assertSee('Ative representantes na gestão de fila para iniciar a distribuição de processos.');
});

it('renders executive mini-kpis, balance status, and cards for 50% / 50% balanced distribution', function () {
    $adminUser = User::factory()->withTwoFactor()->create();
    $adminUser->assignRole('admin');

    $rep1 = ProposalRepresentative::factory()->create([
        'name' => 'Roberto Sérgio Gonçalves',
        'email' => 'roberto.goncalves@bsicapital.com.br',
        'queue_position' => 1,
        'is_active' => true,
    ]);

    $rep2 = ProposalRepresentative::factory()->create([
        'name' => 'Ana Clara Silveira',
        'email' => 'ana.silveira@bsicapital.com.br',
        'queue_position' => 2,
        'is_active' => true,
    ]);

    createRepresentativeProposal($rep1, ProposalStatus::InReview->value);
    createRepresentativeProposal($rep2, ProposalStatus::InReview->value);

    $this->actingAs($adminUser);

    Livewire::test(ProposalRepresentativeLoadChartWidget::class)
        ->assertSee('Carga Operacional da Fila Comercial')
        ->assertSee('Gerenciar Fila')
        // Mini-KPIs
        ->assertSee('Processos ativos')
        ->assertSee('Responsáveis')
        ->assertSee('Média por responsável')
        // Status Geral
        ->assertSee('Distribuição perfeitamente equilibrada')
        // Card 1
        ->assertSee('RO')
        ->assertSee('Roberto Sérgio Gonçalves')
        ->assertSee('Fila #1')
        ->assertSee('roberto.goncalves@bsicapital.com.br')
        ->assertSee('1')
        ->assertSee('processo')
        ->assertSee('50% da carga')
        // Card 2
        ->assertSee('AN')
        ->assertSee('Ana Clara Silveira')
        ->assertSee('Fila #2')
        ->assertSee('ana.silveira@bsicapital.com.br');
});

it('correctly formats cards for multi-representative, long names, long emails, and zero processes', function () {
    $adminUser = User::factory()->withTwoFactor()->create();
    $adminUser->assignRole('admin');

    $repLong = ProposalRepresentative::factory()->create([
        'name' => 'Roberto Sérgio Henrique Gonçalves de Alcantara e Silva',
        'email' => 'roberto_sergio_goncalves_alcantara@extensocorporativo.bsicapital.com.br',
        'queue_position' => 1,
        'is_active' => true,
    ]);

    $repShort = ProposalRepresentative::factory()->create([
        'name' => 'Edu Dias',
        'email' => 'edu@bsi.br',
        'queue_position' => 2,
        'is_active' => true,
    ]);

    $repAvailable = ProposalRepresentative::factory()->create([
        'name' => 'Mariana Torres',
        'email' => 'mariana.torres@bsicapital.com.br',
        'queue_position' => 3,
        'is_active' => true,
    ]);

    $repFour = ProposalRepresentative::factory()->create([
        'name' => 'Carlos Lima',
        'email' => 'carlos.lima@bsicapital.com.br',
        'queue_position' => 4,
        'is_active' => true,
    ]);

    // Give 4 to repLong, 2 to repShort, 1 to repFour, 0 to repAvailable
    for ($i = 0; $i < 4; $i++) {
        createRepresentativeProposal($repLong, ProposalStatus::InReview->value);
    }
    for ($i = 0; $i < 2; $i++) {
        createRepresentativeProposal($repShort, ProposalStatus::AwaitingInformation->value);
    }
    createRepresentativeProposal($repFour, ProposalStatus::InReview->value);

    $this->actingAs($adminUser);

    Livewire::test(ProposalRepresentativeLoadChartWidget::class)
        ->assertSee('7')
        ->assertSee('Processos ativos')
        ->assertSee('4')
        ->assertSee('Responsáveis')
        ->assertSee('Roberto Sérgio Henrique Gonçalves de Alcantara e Silva')
        ->assertSee('roberto_sergio_goncalves_alcantara@extensocorporativo.bsicapital.com.br')
        ->assertSee('Edu Dias')
        ->assertSee('Mariana Torres')
        ->assertSee('Disponível')
        ->assertSee('Carlos Lima')
        ->assertSeeHtml('bg-[#a06e28]')
        ->assertSeeHtml('line-clamp-2')
        ->assertSeeHtml('divide-x')
        ->assertSeeHtml('dark:bg-[#091b23]');
});

it('styles the Gerenciar Fila button as an institutional secondary action integrated into BSI Capital theme', function () {
    $adminUser = User::factory()->withTwoFactor()->create();
    $adminUser->assignRole('admin');

    $this->actingAs($adminUser);

    $test = Livewire::test(ProposalRepresentativeLoadChartWidget::class);

    // Verify button has institutional secondary styling classes and avoids generic gray
    $test->assertSeeHtml('bsi-manage-queue-btn')
        ->assertSeeHtml('border-[#a06e28]/25')
        ->assertSeeHtml('bg-[#091b23]/[0.03]')
        ->assertSeeHtml('text-[#091b23]')
        ->assertSeeHtml('dark:border-[#a06e28]/35')
        ->assertSeeHtml('dark:bg-[#091b23]/60')
        ->assertSeeHtml('dark:text-[#fbfaf8]')
        ->assertDontSeeHtml('border-gray-300/80')
        ->assertDontSeeHtml('dark:bg-gray-800/90');

    // Verify theme CSS contains scoped header alignment and responsive rules
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));
    expect($css)
        ->toContain('.bsi-proposal-representative-load-widget > .fi-section > .fi-section-header')
        ->toContain('.bsi-proposal-representative-load-widget .fi-section-header-after-ctn')
        ->toContain('.bsi-proposal-representative-load-widget .bsi-manage-queue-btn');
});

function createRepresentativeProposal(
    ProposalRepresentative $representative,
    string $status,
): Proposal {
    $company = ProposalCompany::query()->create([
        'name' => 'Empresa Teste '.fake()->unique()->numerify('####'),
        'cnpj' => fake()->unique()->numerify('##.###.###/####-##'),
    ]);

    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
    ]);

    return Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'assigned_representative_id' => $representative->id,
        'status' => $status,
        'distribution_sequence' => fake()->numberBetween(1, 999),
        'distributed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
