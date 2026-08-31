<?php

use App\Filament\Resources\Recruitment\Pages\ListJobApplications;
use App\Models\JobApplication;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::findOrCreate('recruitment.applications.view', 'web');
    Permission::findOrCreate('recruitment.applications.update', 'web');
    Permission::findOrCreate('recruitment.applications.delete', 'web');
});

it('renders the job applications list page with title, subheading and overview metrics', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('recruitment.applications.view');

    $vacancy = Vacancy::factory()->create(['title' => 'Analista Financeiro Pleno']);

    JobApplication::factory()->create([
        'vacancy_id' => $vacancy->id,
        'name' => 'Mariana Costa',
        'email' => 'mariana.costa@example.com',
        'status' => JobApplication::STATUS_NEW,
    ]);

    JobApplication::factory()->create([
        'vacancy_id' => $vacancy->id,
        'name' => 'Roberto Souza',
        'email' => 'roberto.souza@example.com',
        'status' => JobApplication::STATUS_SCREENING,
    ]);

    JobApplication::factory()->create([
        'vacancy_id' => $vacancy->id,
        'name' => 'Juliana Mendes',
        'email' => 'juliana.mendes@example.com',
        'status' => JobApplication::STATUS_INTERVIEW,
    ]);

    $this->actingAs($user);

    Livewire::test(ListJobApplications::class)
        ->assertSuccessful()
        ->assertSee('Candidaturas')
        ->assertSee('Gestão centralizada de inscrições, triagem de talentos e movimentações de candidatos no processo seletivo da BSI.')
        ->assertSee('Todas as Candidaturas')
        ->assertSee('Novas')
        ->assertSee('Em Triagem')
        ->assertSee('Em Entrevista')
        ->assertSee('Finalistas')
        ->assertSee('Encerradas / Histórico')
        ->assertSee('Total de Candidaturas')
        ->assertSee('Mariana Costa')
        ->assertSee('Roberto Souza')
        ->assertSee('Juliana Mendes')
        ->assertSee('Analista Financeiro Pleno');
});

it('filters applications according to the active status tab', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('recruitment.applications.view');

    $vacancy = Vacancy::factory()->create(['title' => 'Engenheiro de Software']);

    $newApp = JobApplication::factory()->create([
        'vacancy_id' => $vacancy->id,
        'name' => 'Lucas Novaes',
        'email' => 'lucas@example.com',
        'status' => JobApplication::STATUS_NEW,
    ]);

    $interviewApp = JobApplication::factory()->create([
        'vacancy_id' => $vacancy->id,
        'name' => 'Fernanda Lima',
        'email' => 'fernanda@example.com',
        'status' => JobApplication::STATUS_INTERVIEW,
    ]);

    $this->actingAs($user);

    // Tab: Novas
    Livewire::test(ListJobApplications::class)
        ->set('activeTab', 'novas')
        ->assertSee('Lucas Novaes')
        ->assertDontSee('Fernanda Lima');

    // Tab: Em Entrevista
    Livewire::test(ListJobApplications::class)
        ->set('activeTab', 'entrevista')
        ->assertSee('Fernanda Lima')
        ->assertDontSee('Lucas Novaes');
});

it('renders the enhanced empty state when there are no applications', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('recruitment.applications.view');

    $this->actingAs($user);

    Livewire::test(ListJobApplications::class)
        ->assertSuccessful()
        ->assertSee('Nenhuma candidatura encontrada')
        ->assertSee('Não há inscrições correspondentes aos critérios')
        ->assertSee('Gerenciar Vagas');
});
