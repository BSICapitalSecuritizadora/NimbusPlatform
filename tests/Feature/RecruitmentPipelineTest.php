<?php

use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Filament\Resources\Recruitment\VacancyResource;
use App\Models\JobApplication;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('stores new applications with the default recruitment pipeline status', function () {
    config(['filesystems.default' => 'public']);
    Storage::fake('public');

    $vacancy = Vacancy::factory()->create([
        'is_active' => true,
    ]);

    $response = $this->post(route('site.vacancies.apply', $vacancy->id), [
        'name' => 'Maria Souza',
        'email' => 'maria@example.com',
        'phone' => '(11) 99999-0000',
        'linkedin_url' => 'https://linkedin.com/in/mariasouza',
        'resume' => UploadedFile::fake()->create('curriculo.pdf', 256, 'application/pdf'),
        'message' => 'Tenho experiência no mercado de capitais.',
    ]);

    $response
        ->assertRedirect()
        ->assertSessionHas('success');

    $application = JobApplication::query()->firstOrFail();

    expect($application->status)->toBe(JobApplication::STATUS_NEW)
        ->and($application->internal_notes)->toBeNull()
        ->and($application->reviewed_at)->toBeNull()
        ->and($application->reviewed_by_user_id)->toBeNull();

    Storage::disk('public')->assertExists($application->resume_path);
});

it('exposes consistent labels and colors for the recruitment pipeline', function () {
    expect(JobApplication::statusOptions())->toMatchArray([
        JobApplication::STATUS_NEW => 'Nova',
        JobApplication::STATUS_SCREENING => 'Triagem',
        JobApplication::STATUS_INTERVIEW => 'Entrevista',
        JobApplication::STATUS_FINALIST => 'Finalista',
        JobApplication::STATUS_HIRED => 'Contratada',
        JobApplication::STATUS_REJECTED => 'Reprovada',
    ]);

    expect(JobApplication::statusLabelFor(JobApplication::STATUS_SCREENING))->toBe('Triagem')
        ->and(JobApplication::statusColorFor(JobApplication::STATUS_NEW))->toBe('warning')
        ->and(JobApplication::statusColorFor(JobApplication::STATUS_REJECTED))->toBe('danger');
});

it('shows navigation badges for active vacancies and new applications', function () {
    $vacancy = Vacancy::factory()->create([
        'is_active' => true,
    ]);

    Vacancy::factory()->count(2)->create([
        'is_active' => true,
    ]);
    Vacancy::factory()->inactive()->create();

    JobApplication::factory()->count(2)->create([
        'vacancy_id' => $vacancy->id,
        'status' => JobApplication::STATUS_NEW,
    ]);
    JobApplication::factory()->create([
        'vacancy_id' => $vacancy->id,
        'status' => JobApplication::STATUS_SCREENING,
    ]);

    expect(VacancyResource::getNavigationBadge())->toBe('3')
        ->and(JobApplicationResource::getNavigationBadge())->toBe('2');
});
