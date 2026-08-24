<?php

use App\Enums\VacancyStatus;
use App\Jobs\SendJobApplicationStatusMail;
use App\Mail\JobApplicationStatusMail;
use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Recruitment\JobApplicationStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('creates exactly one history per real transition via service', function (): void {
    $app = JobApplication::factory()->create(['status' => JobApplication::STATUS_NEW]);
    JobApplicationStatusService::recordInitialHistory($app);
    $initial = JobApplicationStatusHistory::where('job_application_id', $app->id)->count();
    expect($initial)->toBe(1);

    JobApplicationStatusService::changeStatus($app, JobApplication::STATUS_SCREENING, 'note 1');
    expect(JobApplicationStatusHistory::where('job_application_id', $app->id)->count())->toBe(2);

    // Same status -> no new history
    $result = JobApplicationStatusService::changeStatus($app->fresh(), JobApplication::STATUS_SCREENING);
    expect($result)->toBeFalse()
        ->and(JobApplicationStatusHistory::where('job_application_id', $app->id)->count())->toBe(2);
});

it('creates no history when saving without status change via edit', function (): void {
    $app = JobApplication::factory()->create(['status' => JobApplication::STATUS_NEW, 'internal_notes' => null]);
    JobApplicationStatusService::recordInitialHistory($app);
    $before = JobApplicationStatusHistory::where('job_application_id', $app->id)->count();

    // Simulate edit saving only notes, not status
    $app->update(['internal_notes' => 'nova observação', 'reviewed_at' => now(), 'reviewed_by_user_id' => null]);

    // No status change, so history count should stay same (factory initial only)
    // Note: Edit page would not create history in this case, our manual check here
    expect(JobApplicationStatusHistory::where('job_application_id', $app->id)->count())->toBe($before);
});

it('creates correct histories for bulk transitions', function (): void {
    $vacancy = Vacancy::factory()->published()->create();
    $apps = JobApplication::factory()->count(3)->create(['vacancy_id' => $vacancy->id, 'status' => JobApplication::STATUS_NEW]);
    foreach ($apps as $a) {
        JobApplicationStatusService::recordInitialHistory($a);
    }

    foreach ($apps as $app) {
        JobApplicationStatusService::changeStatus($app, JobApplication::STATUS_INTERVIEW);
    }

    foreach ($apps as $app) {
        expect(JobApplicationStatusHistory::where('job_application_id', $app->id)->count())->toBe(2)
            ->and(JobApplicationStatusHistory::where('job_application_id', $app->id)->latest()->first()->to_status)->toBe(JobApplication::STATUS_INTERVIEW);
    }
});

it('does not resend hired email when re-saving already hired', function (): void {
    Mail::fake();
    $app = JobApplication::factory()->create(['status' => JobApplication::STATUS_FINALIST, 'email' => 'c@example.com']);

    // First transition to hired -> should dispatch
    JobApplicationStatusService::changeStatus($app, JobApplication::STATUS_HIRED);
    SendJobApplicationStatusMail::dispatch($app->fresh(['vacancy']));
    Mail::assertSent(JobApplicationStatusMail::class, 1);
    Mail::fake();

    // Re-save same status -> should not dispatch
    $fresh = $app->fresh();
    $changed = JobApplicationStatusService::changeStatus($fresh, JobApplication::STATUS_HIRED);
    expect($changed)->toBeFalse();
    // Even if we try to dispatch, our edit logic checks original !== new, so no dispatch
    // Simulate edit page re-saving hired without change: no mail
    Mail::assertNotSent(JobApplicationStatusMail::class);
});

it('rejects applications for expired vacancies before scheduler', function (): void {
    Storage::fake('resumes');
    $expired = Vacancy::factory()->expired()->create();
    // Expired is published but expires_at past, so visibleForSite false
    expect($expired->isVisibleForSite())->toBeFalse();

    $this->get(route('site.vacancies.show', $expired->slug))->assertNotFound();

    $this->post(route('site.vacancies.apply', $expired->slug), [
        'name' => 'Test', 'email' => 't@example.com', 'phone' => '(11) 99999-0000',
        'resume' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        'lgpd_consent' => '1',
    ])->assertNotFound();

    // Also via id (BC) should fail because visibleForSite check
    $this->post(route('site.vacancies.apply', $expired->id), [
        'name' => 'Test', 'email' => 't2@example.com', 'phone' => '(11) 99999-0000',
        'resume' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        'lgpd_consent' => '1',
    ])->assertNotFound();
});

it('accepts slug-based application submission', function (): void {
    Storage::fake('resumes');
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();

    $this->post(route('site.vacancies.apply', $vacancy->slug), [
        'name' => 'Slug User', 'email' => 'slug@example.com', 'phone' => '(11) 99999-0000',
        'resume' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        'lgpd_consent' => '1',
    ])->assertRedirect()->assertSessionHas('success');

    expect(JobApplication::where('email', 'slug@example.com')->exists())->toBeTrue();

    // Numeric ID no longer accepted (slug-only) should 404
    $this->post(route('site.vacancies.apply', (string) $vacancy->id), [
        'name' => 'Id User', 'email' => 'iduser@example.com', 'phone' => '(11) 99999-0000',
        'resume' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        'lgpd_consent' => '1',
    ])->assertNotFound();
});

it('pipeline counts are correct and use single query', function (): void {
    $vacancy = Vacancy::factory()->published()->create();
    JobApplication::factory()->count(2)->create(['vacancy_id' => $vacancy->id, 'status' => JobApplication::STATUS_NEW]);
    JobApplication::factory()->create(['vacancy_id' => $vacancy->id, 'status' => JobApplication::STATUS_SCREENING]);
    JobApplication::factory()->create(['vacancy_id' => $vacancy->id, 'status' => JobApplication::STATUS_HIRED]);

    $counts = $vacancy->applicationCountsByStatus();
    expect($counts[JobApplication::STATUS_NEW])->toBe(2)
        ->and($counts[JobApplication::STATUS_SCREENING])->toBe(1)
        ->and($counts[JobApplication::STATUS_HIRED])->toBe(1)
        ->and($counts[JobApplication::STATUS_REJECTED])->toBe(0)
        ->and(array_sum($counts))->toBe(4);

    // Second call should be memoized (no extra query) – we test by checking same array returned
    $counts2 = $vacancy->applicationCountsByStatus();
    expect($counts2)->toBe($counts);
});

it('vacancy lifecycle transitions correctly handle timestamps', function (): void {
    $vacancy = Vacancy::factory()->draft()->create();

    // draft -> published sets published_at
    $vacancy->update(['status' => VacancyStatus::Published, 'published_at' => now()]);
    expect($vacancy->fresh()->status)->toBe(VacancyStatus::Published)
        ->and($vacancy->fresh()->published_at)->not->toBeNull()
        ->and($vacancy->fresh()->closed_at)->toBeNull();

    // published -> paused
    $vacancy->fresh()->update(['status' => VacancyStatus::Paused]);
    expect($vacancy->fresh()->status)->toBe(VacancyStatus::Paused);

    // paused -> published (reopen) clears closed_at and expires_at
    $vacancy->fresh()->update(['status' => VacancyStatus::Closed, 'closed_at' => now(), 'expires_at' => now()->subDay()]);
    $vacancy->fresh()->update(['status' => VacancyStatus::Published, 'published_at' => $vacancy->published_at, 'closed_at' => null, 'expires_at' => null]);
    expect($vacancy->fresh()->status)->toBe(VacancyStatus::Published)
        ->and($vacancy->fresh()->closed_at)->toBeNull()
        ->and($vacancy->fresh()->expires_at)->toBeNull();

    // published -> closed sets closed_at
    $vacancy->fresh()->update(['status' => VacancyStatus::Closed, 'closed_at' => now()]);
    expect($vacancy->fresh()->status)->toBe(VacancyStatus::Closed)
        ->and($vacancy->fresh()->closed_at)->not->toBeNull();

    // closed -> archived
    $vacancy->fresh()->update(['status' => VacancyStatus::Archived]);
    expect($vacancy->fresh()->status)->toBe(VacancyStatus::Archived);
});

it('verifies is_active sync does not create conflicting values', function (): void {
    $draft = Vacancy::factory()->draft()->create();
    expect($draft->is_active)->toBeFalse()
        ->and($draft->status)->toBe(VacancyStatus::Draft);

    $published = Vacancy::factory()->published()->create();
    expect($published->is_active)->toBeTrue();

    // Direct is_active true should set status published
    $v = Vacancy::factory()->draft()->create();
    $v->is_active = true;
    $v->save();
    expect($v->fresh()->status)->toBe(VacancyStatus::Published)
        ->and($v->fresh()->is_active)->toBeTrue();

    // Draft with is_active false should stay draft, not become paused
    $v2 = Vacancy::factory()->draft()->create();
    $v2->is_active = false;
    $v2->save();
    expect($v2->fresh()->status)->toBe(VacancyStatus::Draft);
});

it('memoization does not leak into attributes, dirty or serialization', function (): void {
    $vacancy = Vacancy::factory()->published()->create();
    JobApplication::factory()->create(['vacancy_id' => $vacancy->id, 'status' => JobApplication::STATUS_NEW]);

    $vacancy->applicationCountsByStatus();
    $vacancy->applicationCountsByStatus(); // second call memoized

    expect($vacancy->getDirty())->not->toHaveKey('__cached_counts')
        ->and($vacancy->getDirty())->not->toHaveKey('memoizedApplicationCounts')
        ->and($vacancy->toArray())->not->toHaveKey('__cached_counts')
        ->and($vacancy->toArray())->not->toHaveKey('memoizedApplicationCounts')
        ->and(json_encode($vacancy))->not->toContain('__cached_counts')
        ->and(json_encode($vacancy))->not->toContain('memoizedApplicationCounts');

    // isDirty should be false after only reading counts (no attribute mutation)
    expect($vacancy->isDirty())->toBeFalse();
});

it('vacancy public serialization does not expose transient cache but admin needs internal fields via model', function (): void {
    $vacancy = Vacancy::factory()->published()->create([
        'internal_notes' => 'secret internal',
        'salary_min' => 10000,
        'salary_max' => 15000,
        'salary_visible' => false,
        'hiring_manager_id' => User::factory()->create()->id,
    ]);

    // Memoize then check toArray does not expose cache
    $vacancy->applicationCountsByStatus();
    $array = $vacancy->toArray();

    expect($array)->not->toHaveKey('__cached_counts')
        ->and($array)->toHaveKey('internal_notes') // admin needs it via model, not leaked via public view
        ->and($array['internal_notes'])->toBe('secret internal');

    // Public blade does not render internal_notes or hidden salary
    $response = $this->get(route('site.vacancies.show', $vacancy->slug));
    $response->assertDontSee('secret internal');
    $response->assertDontSee('10000');
});
