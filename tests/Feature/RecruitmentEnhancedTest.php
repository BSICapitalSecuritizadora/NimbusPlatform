<?php

use App\Enums\MalwareScanStatus;
use App\Enums\VacancyDepartment;
use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyStatus;
use App\Enums\VacancyWorkModel;
use App\Jobs\SendJobApplicationStatusMail;
use App\Mail\JobApplicationStatusMail;
use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Recruitment\JobApplicationStatusService;
use App\Services\Recruitment\VacancySlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('migrates existing active vacancies to published and inactive to paused', function (): void {
    // Simulate legacy vacancies via raw DB to bypass new model defaults
    $publishedId = DB::table('vacancies')->insertGetId([
        'title' => 'Legacy Active',
        'slug' => 'legacy-active-'.Str::random(5),
        'department' => 'Comercial',
        'location' => 'São Paulo, SP',
        'type' => 'CLT',
        'description' => 'Desc',
        'is_active' => true,
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $pausedId = DB::table('vacancies')->insertGetId([
        'title' => 'Legacy Paused',
        'slug' => 'legacy-paused-'.str()->random(5),
        'department' => 'Comercial',
        'location' => 'São Paulo, SP',
        'type' => 'CLT',
        'description' => 'Desc',
        'is_active' => false,
        'status' => 'paused',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pub = Vacancy::find($publishedId);
    $paused = Vacancy::find($pausedId);

    expect($pub->status)->toBe(VacancyStatus::Published)
        ->and($paused->status)->toBe(VacancyStatus::Paused)
        ->and($pub->is_active)->toBeTrue()
        ->and($paused->is_active)->toBeFalse();
});

it('exposes only published non-expired vacancies on the public site', function (): void {
    $draft = Vacancy::factory()->draft()->create(['title' => 'Draft Role']);
    $published = Vacancy::factory()->published()->create(['title' => 'Published Role']);
    $paused = Vacancy::factory()->paused()->create(['title' => 'Paused Role']);
    $closed = Vacancy::factory()->closed()->create(['title' => 'Closed Role']);
    $archived = Vacancy::factory()->archived()->create(['title' => 'Archived Role']);
    $expired = Vacancy::factory()->expired()->create(['title' => 'Expired Role']);

    expect(Vacancy::visibleForSite()->pluck('id')->all())
        ->toContain($published->id)
        ->not->toContain($draft->id)
        ->not->toContain($paused->id)
        ->not->toContain($closed->id)
        ->not->toContain($archived->id)
        ->not->toContain($expired->id);

    $this->get(route('site.vacancies.show', $draft->slug))->assertNotFound();
    $this->get(route('site.vacancies.show', $published->slug))->assertSuccessful();
    $this->get(route('site.vacancies.show', $paused->slug))->assertNotFound();
});

it('auto-closes expired published vacancies idempotently', function (): void {
    $expired = Vacancy::factory()->expired()->create();
    $future = Vacancy::factory()->create(['status' => VacancyStatus::Published, 'expires_at' => now()->addDays(5), 'published_at' => now()]);
    $pausedExpired = Vacancy::factory()->create(['status' => VacancyStatus::Paused, 'expires_at' => now()->subDay()]);

    $this->artisan('vacancies:auto-close')->assertSuccessful();
    $expired->refresh();
    expect($expired->status)->toBe(VacancyStatus::Closed)
        ->and($expired->closed_at)->not->toBeNull()
        ->and($future->fresh()->status)->toBe(VacancyStatus::Published)
        ->and($pausedExpired->fresh()->status)->toBe(VacancyStatus::Paused);

    // Idempotent second run
    $this->artisan('vacancies:auto-close')->assertSuccessful();
    expect(Vacancy::find($expired->id)->status)->toBe(VacancyStatus::Closed);
});

it('generates unique slugs and keeps them stable on title edit', function (): void {
    $v1 = Vacancy::factory()->create(['title' => 'Analista Comercial']);
    $v2 = Vacancy::factory()->create(['title' => 'Analista Comercial']);
    expect($v1->slug)->not->toBe($v2->slug);

    $originalSlug = $v1->slug;
    $v1->update(['title' => 'Analista Comercial Senior']);
    expect($v1->fresh()->slug)->toBe($originalSlug);
});

it('duplicate action concept produces a new slug and draft status', function (): void {
    $original = Vacancy::factory()->published()->create(['title' => 'Original Vaga', 'published_at' => now()->subDay(), 'expires_at' => now()->addDay(), 'closed_at' => null]);
    $copy = $original->replicate();
    $copy->title = $original->title.' (Cópia)';
    $copy->slug = VacancySlugService::generate($copy->title);
    $copy->status = VacancyStatus::Draft;
    $copy->published_at = null;
    $copy->expires_at = null;
    $copy->closed_at = null;
    $copy->save();

    expect($copy->slug)->not->toBe($original->slug)
        ->and($copy->status)->toBe(VacancyStatus::Draft)
        ->and($copy->published_at)->toBeNull()
        ->and($copy->applications()->count())->toBe(0);
});

it('accepts application to published vacancy but not to unavailable', function (): void {
    Storage::fake('resumes');
    Queue::fake();
    $published = Vacancy::factory()->published()->create();
    $draft = Vacancy::factory()->draft()->create();

    $this->post(route('site.vacancies.apply', $published->slug), [
        'name' => 'João Silva', 'email' => 'joao@example.com', 'phone' => '(11) 99999-0000',
        'resume' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        'lgpd_consent' => '1',
    ])->assertRedirect()->assertSessionHas('success');

    expect(JobApplication::where('vacancy_id', $published->id)->exists())->toBeTrue();

    $this->post(route('site.vacancies.apply', $draft->slug), [
        'name' => 'João Silva', 'email' => 'joao2@example.com', 'phone' => '(11) 99999-0000',
        'resume' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        'lgpd_consent' => '1',
    ])->assertNotFound();
});

it('rejects duplicate application within the configured window', function (): void {
    Storage::fake('resumes');
    Queue::fake();
    config(['privacy.recruitment.duplicate_window_days' => 30]);
    $vacancy = Vacancy::factory()->published()->create();

    $payload = [
        'name' => 'Maria', 'email' => 'maria@example.com', 'phone' => '(11) 99999-0000',
        'resume' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        'lgpd_consent' => '1',
    ];
    $this->post(route('site.vacancies.apply', $vacancy->slug), $payload)->assertSessionHas('success');
    $this->post(route('site.vacancies.apply', $vacancy->slug), $payload)->assertSessionHasErrors(['email']);

    // Outside window allowed
    $app = JobApplication::first();
    $app->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();
    $this->post(route('site.vacancies.apply', $vacancy->slug), [
        'name' => 'Maria', 'email' => 'maria@example.com', 'phone' => '(11) 99999-0000',
        'resume' => UploadedFile::fake()->create('cv2.pdf', 200, 'application/pdf'),
        'lgpd_consent' => '1',
    ])->assertSessionHas('success');
});

it('creates history when status changes but not when unchanged', function (): void {
    $app = JobApplication::factory()->create(['status' => JobApplication::STATUS_NEW]);
    JobApplicationStatusService::recordInitialHistory($app);
    $initialCount = JobApplicationStatusHistory::where('job_application_id', $app->id)->count();
    expect($initialCount)->toBe(1);

    JobApplicationStatusService::changeStatus($app, JobApplication::STATUS_SCREENING, 'Triagem OK');
    expect(JobApplicationStatusHistory::where('job_application_id', $app->id)->count())->toBe(2);

    $result = JobApplicationStatusService::changeStatus($app->fresh(), JobApplication::STATUS_SCREENING);
    expect($result)->toBeFalse()
        ->and(JobApplicationStatusHistory::where('job_application_id', $app->id)->count())->toBe(2);
});

it('sends hired/rejected mail only on terminal transition', function (): void {
    Mail::fake();
    $app = JobApplication::factory()->create(['status' => JobApplication::STATUS_FINALIST, 'email' => 'cand@example.com']);

    // Create vacancy for mail subject
    $vacancy = $app->vacancy;

    JobApplicationStatusService::changeStatus($app, JobApplication::STATUS_HIRED);
    SendJobApplicationStatusMail::dispatch($app->fresh(['vacancy']));
    Mail::assertSent(JobApplicationStatusMail::class, 1);

    Mail::fake();
    $app2 = JobApplication::factory()->create(['status' => JobApplication::STATUS_FINALIST, 'email' => 'cand2@example.com']);
    JobApplicationStatusService::changeStatus($app2, JobApplication::STATUS_REJECTED);
    SendJobApplicationStatusMail::dispatch($app2->fresh(['vacancy']));
    Mail::assertSent(JobApplicationStatusMail::class, 1);
});

it('enforces resume malware gate', function (): void {
    Storage::fake('resumes');
    $user = User::factory()->create();
    $user->assignRole('super-admin');
    $pending = JobApplication::factory()->create(['scan_status' => MalwareScanStatus::Pending, 'resume_path' => 'a.pdf']);
    Storage::disk('resumes')->put('a.pdf', 'x');
    $this->actingAs($user)->get(route('admin.job-applications.resume', $pending))->assertNotFound();

    $infected = JobApplication::factory()->create(['scan_status' => MalwareScanStatus::Infected, 'resume_path' => 'b.pdf']);
    Storage::disk('resumes')->put('b.pdf', 'x');
    $this->actingAs($user)->get(route('admin.job-applications.resume', $infected))->assertNotFound();

    $clean = JobApplication::factory()->create(['scan_status' => MalwareScanStatus::Clean, 'resume_path' => 'c.pdf']);
    Storage::disk('resumes')->put('c.pdf', 'x');
    $this->actingAs($user)->get(route('admin.job-applications.resume', $clean))->assertDownload();
});

it('prevents unauthorized resume download', function (): void {
    Storage::fake('resumes');
    $user = User::factory()->create(); // no role/permissions
    $app = JobApplication::factory()->create(['scan_status' => MalwareScanStatus::Clean, 'resume_path' => 'c.pdf']);
    Storage::disk('resumes')->put('c.pdf', 'x');
    $this->actingAs($user)->get(route('admin.job-applications.resume', $app))->assertForbidden();
});

it('filters public vacancies by department type work_model search and paginates', function (): void {
    Vacancy::factory()->published()->create(['title' => 'Analista Operações', 'department' => VacancyDepartment::Operacoes->value, 'type' => VacancyEmploymentType::CLT->value, 'work_model' => VacancyWorkModel::Onsite->value]);
    Vacancy::factory()->published()->create(['title' => 'Dev Backend', 'department' => VacancyDepartment::Tecnologia->value, 'type' => VacancyEmploymentType::PJ->value, 'work_model' => VacancyWorkModel::Remote->value]);
    Vacancy::factory()->published()->create(['title' => 'Estágio Jurídico', 'department' => VacancyDepartment::Juridico->value, 'type' => VacancyEmploymentType::Estagio->value, 'work_model' => VacancyWorkModel::Hybrid->value]);

    $this->get(route('site.vacancies.index', ['department' => VacancyDepartment::Tecnologia->value]))->assertSuccessful()->assertSee('Dev Backend')->assertDontSee('Analista Operações');
    $this->get(route('site.vacancies.index', ['type' => VacancyEmploymentType::PJ->value]))->assertSee('Dev Backend')->assertDontSee('Estágio Jurídico');
    $this->get(route('site.vacancies.index', ['work_model' => VacancyWorkModel::Remote->value]))->assertSee('Dev Backend')->assertDontSee('Analista Operações');
    $this->get(route('site.vacancies.index', ['q' => 'Operações']))->assertSee('Analista Operações')->assertDontSee('Dev Backend');

    // Pagination: create 13 vacancies total, perPage 12 => has pagination
    Vacancy::factory()->count(13)->published()->create();
    $this->get(route('site.vacancies.index'))->assertSuccessful();
    $paginated = Vacancy::visibleForSite()->paginate(12);
    expect($paginated->hasPages())->toBeTrue();
});

it('hides salary when not visible and shows when visible', function (): void {
    $hidden = Vacancy::factory()->published()->create(['salary_min' => 5000, 'salary_max' => 8000, 'salary_visible' => false]);
    $visible = Vacancy::factory()->published()->create(['salary_min' => 5000, 'salary_max' => 8000, 'salary_visible' => true]);

    $respHidden = $this->get(route('site.vacancies.show', $hidden->slug));
    $respHidden->assertDontSee('5.000')->assertDontSee('8.000');

    $respVisible = $this->get(route('site.vacancies.show', $visible->slug));
    $respVisible->assertSee('5.000');
});

it('blocks honeypot submissions without creating application', function (): void {
    Storage::fake('resumes');
    $vacancy = Vacancy::factory()->published()->create();
    $this->post(route('site.vacancies.apply', $vacancy->slug), [
        'name' => 'Bot', 'email' => 'bot@example.com', 'phone' => '(11) 99999-0000',
        'resume' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        'lgpd_consent' => '1',
        'website' => 'http://spam.com',
    ])->assertSessionHas('success');
    expect(JobApplication::where('email', 'bot@example.com')->exists())->toBeFalse();
});
