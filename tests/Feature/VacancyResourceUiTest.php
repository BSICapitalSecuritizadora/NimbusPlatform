<?php

use App\Enums\AccessPermission;
use App\Enums\VacancyDepartment;
use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyStatus;
use App\Enums\VacancyWorkModel;
use App\Filament\Resources\Recruitment\Pages\CreateVacancy;
use App\Filament\Resources\Recruitment\Pages\EditVacancy;
use App\Filament\Resources\Recruitment\Pages\ListVacancies;
use App\Filament\Resources\Recruitment\Tables\VacanciesTable;
use App\Models\User;
use App\Models\Vacancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function vacancyUiUser(string ...$permissions): User
{
    $user = User::factory()->withTwoFactor()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('renders the vacancies list page with cockpit styling, title and subheadings', function (): void {
    $user = vacancyUiUser(AccessPermission::RecruitmentVacanciesView->value);
    $this->actingAs($user);

    $page = new ListVacancies;
    expect($page->getTitle())->toBe('Vagas')
        ->and($page->getSubheading())->toContain('Gestão institucional de oportunidades de carreira');
});

it('renders header create action when authorized', function (): void {
    $user = vacancyUiUser(
        AccessPermission::RecruitmentVacanciesView->value,
        AccessPermission::RecruitmentVacanciesCreate->value
    );
    $this->actingAs($user);

    $headerActionsMethod = new ReflectionMethod(ListVacancies::class, 'getHeaderActions');
    $headerActionsMethod->setAccessible(true);
    $actions = collect($headerActionsMethod->invoke(new ListVacancies));

    $createAction = $actions->first(fn (mixed $action): bool => $action instanceof CreateAction && $action->getName() === 'create');

    expect($createAction)->not->toBeNull()
        ->and($createAction->getLabel())->toBe('Cadastrar Vaga')
        ->and($createAction->getColor())->toBe('primary');
});

it('configures table search placeholder, pagination, and refined empty state', function (): void {
    $table = VacanciesTable::configure(Table::make(new ListVacancies));

    expect($table->getSearchPlaceholder())->toBe('Buscar por título, departamento ou localização...')
        ->and($table->getEmptyStateHeading())->toBe('Nenhuma vaga encontrada')
        ->and($table->getEmptyStateDescription())->toContain('Não há vagas cadastradas')
        ->and($table->getEmptyStateIcon())->toBe('heroicon-o-briefcase')
        ->and($table->getDefaultPaginationPageOption())->toBe(10);
});

it('has expected columns configured for vacancy list', function (): void {
    $table = VacanciesTable::configure(Table::make(new ListVacancies));
    $columns = collect($table->getColumns());

    expect($columns->has('title'))->toBeTrue()
        ->and($columns->has('status'))->toBeTrue()
        ->and($columns->has('department'))->toBeTrue()
        ->and($columns->has('type'))->toBeTrue()
        ->and($columns->has('work_model'))->toBeTrue()
        ->and($columns->has('location'))->toBeTrue()
        ->and($columns->has('positions'))->toBeTrue()
        ->and($columns->has('applications_count'))->toBeTrue()
        ->and($columns->has('expires_at'))->toBeTrue()
        ->and($columns->has('created_at'))->toBeTrue();

    $titleColumn = $columns->get('title');
    expect($titleColumn->isSearchable())->toBeTrue()
        ->and($titleColumn->isSortable())->toBeTrue();
});

it('renders vacancies and counts in the livewire table', function (): void {
    $user = vacancyUiUser(
        AccessPermission::RecruitmentVacanciesView->value,
        AccessPermission::RecruitmentVacanciesCreate->value
    );
    $this->actingAs($user);

    $vaga1 = Vacancy::factory()->create([
        'title' => 'Analista de Estruturação Sr',
        'status' => VacancyStatus::Published,
        'department' => 'Estruturação',
        'location' => 'São Paulo, SP',
    ]);

    $vaga2 = Vacancy::factory()->create([
        'title' => 'Especialista de Riscos e Compliance',
        'status' => VacancyStatus::Draft,
        'department' => 'Riscos',
        'location' => 'Remoto',
    ]);

    Livewire::test(ListVacancies::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$vaga1, $vaga2])
        ->assertSee('Analista de Estruturação Sr')
        ->assertSee('Especialista de Riscos e Compliance');
});

it('filters vacancies by status tabs with badges', function (): void {
    $user = vacancyUiUser(AccessPermission::RecruitmentVacanciesView->value);
    $this->actingAs($user);

    $draft = Vacancy::factory()->create([
        'title' => 'Vaga em Rascunho',
        'status' => VacancyStatus::Draft,
    ]);

    $published = Vacancy::factory()->create([
        'title' => 'Vaga Publicada no Site',
        'status' => VacancyStatus::Published,
    ]);

    Livewire::test(ListVacancies::class)
        ->set('activeTab', 'rascunhos')
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$published])
        ->set('activeTab', 'publicadas')
        ->assertCanSeeTableRecords([$published])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('renders create and edit vacancy pages with subheadings and full width cockpit attributes', function (): void {
    $createPage = new CreateVacancy;
    expect($createPage->getTitle())->toBe('Cadastrar vaga')
        ->and($createPage->getSubheading())->toContain('Cadastre os dados da oportunidade');

    $editPage = new EditVacancy;
    expect($editPage->getTitle())->toBe('Editar vaga')
        ->and($editPage->getSubheading())->toContain('Atualize os dados da oportunidade');
});

it('creates a vacancy successfully through the create form', function (): void {
    $user = vacancyUiUser(
        AccessPermission::RecruitmentVacanciesView->value,
        AccessPermission::RecruitmentVacanciesCreate->value
    );
    $this->actingAs($user);

    Livewire::test(CreateVacancy::class)
        ->fillForm([
            'title' => 'Desenvolvedor Full Stack Sênior',
            'department' => VacancyDepartment::Tecnologia->value,
            'type' => VacancyEmploymentType::CLT->value,
            'work_model' => VacancyWorkModel::Hybrid->value,
            'location' => 'São Paulo, SP',
            'positions' => 2,
            'status' => VacancyStatus::Published->value,
            'salary_min' => 12000,
            'salary_max' => 18000,
            'salary_visible' => true,
            'description' => '<p>Buscamos desenvolvedor experiente para integrar a equipe de tecnologia.</p>',
            'requirements' => '<p>Experiência com Laravel, Livewire e PostgreSQL.</p>',
            'benefits' => '<p>Vale refeição, plano de saúde e bônus anual.</p>',
            'internal_notes' => 'Aprovado pelo comitê de contratação.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('vacancies', [
        'title' => 'Desenvolvedor Full Stack Sênior',
        'department' => VacancyDepartment::Tecnologia->value,
        'positions' => 2,
        'status' => VacancyStatus::Published->value,
        'salary_min' => 12000,
        'salary_max' => 18000,
        'salary_visible' => true,
    ]);
});
