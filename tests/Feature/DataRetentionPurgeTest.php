<?php

use App\Models\ContactMessage;
use App\Models\JobApplication;
use App\Models\Vacancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('resumes');
});

function jobApplicationSentMonthsAgo(int $months, string $resumePath = 'curriculo.pdf'): JobApplication
{
    Storage::disk('resumes')->put($resumePath, 'conteudo');

    $application = JobApplication::factory()->for(Vacancy::factory())->create([
        'resume_path' => $resumePath,
        'status' => JobApplication::STATUS_NEW,
    ]);

    // `created_at` é gerenciado pelo Eloquent; recuar exige escrita direta.
    $application->forceFill(['created_at' => now()->subMonths($months)])->saveQuietly();

    return $application;
}

it('deletes job applications past the retention window', function () {
    $expired = jobApplicationSentMonthsAgo(18, 'antigo.pdf');
    $recent = jobApplicationSentMonthsAgo(2, 'recente.pdf');

    $this->artisan('lgpd:purge-job-applications', ['--months' => 12])
        ->assertSuccessful();

    expect(JobApplication::query()->find($expired->id))->toBeNull()
        ->and(JobApplication::query()->find($recent->id))->not->toBeNull();
});

/**
 * Apagar a linha e deixar o PDF no disco não elimina o dado pessoal — só o
 * torna órfão e mais difícil de encontrar numa auditoria.
 */
it('deletes the resume file together with the record', function () {
    jobApplicationSentMonthsAgo(18, 'antigo.pdf');
    jobApplicationSentMonthsAgo(2, 'recente.pdf');

    $this->artisan('lgpd:purge-job-applications', ['--months' => 12])
        ->assertSuccessful();

    Storage::disk('resumes')->assertMissing('antigo.pdf');
    Storage::disk('resumes')->assertExists('recente.pdf');
});

it('reports without deleting anything on a dry run', function () {
    $expired = jobApplicationSentMonthsAgo(18, 'antigo.pdf');

    $this->artisan('lgpd:purge-job-applications', ['--months' => 12, '--dry-run' => true])
        ->assertSuccessful();

    expect(JobApplication::query()->find($expired->id))->not->toBeNull();
    Storage::disk('resumes')->assertExists('antigo.pdf');
});

it('does nothing when the retention window is disabled', function () {
    $expired = jobApplicationSentMonthsAgo(60, 'antigo.pdf');

    $this->artisan('lgpd:purge-job-applications', ['--months' => 0])
        ->assertSuccessful();

    expect(JobApplication::query()->find($expired->id))->not->toBeNull();
});

it('deletes contact messages past the retention window', function () {
    $expired = ContactMessage::factory()->create();
    $expired->forceFill(['created_at' => now()->subMonths(30)])->saveQuietly();

    $recent = ContactMessage::factory()->create();
    $recent->forceFill(['created_at' => now()->subMonths(3)])->saveQuietly();

    $this->artisan('lgpd:purge-contact-messages', ['--months' => 24])
        ->assertSuccessful();

    expect(ContactMessage::query()->find($expired->id))->toBeNull()
        ->and(ContactMessage::query()->find($recent->id))->not->toBeNull();
});

it('keeps contact messages on a dry run', function () {
    $expired = ContactMessage::factory()->create();
    $expired->forceFill(['created_at' => now()->subMonths(30)])->saveQuietly();

    $this->artisan('lgpd:purge-contact-messages', ['--months' => 24, '--dry-run' => true])
        ->assertSuccessful();

    expect(ContactMessage::query()->find($expired->id))->not->toBeNull();
});

it('schedules both purges so retention does not depend on someone remembering', function () {
    $scheduledNames = collect(app(Schedule::class)->events())
        ->map(fn ($event): ?string => $event->description)
        ->all();

    expect($scheduledNames)
        ->toContain('lgpd-purge-job-applications')
        ->toContain('lgpd-purge-contact-messages');
});

it('documents the retention periods so they are not buried in code', function () {
    expect(config('privacy.retention.job_applications.months'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('privacy.retention.contact_messages.months'))->toBeInt()->toBeGreaterThan(0);
});
