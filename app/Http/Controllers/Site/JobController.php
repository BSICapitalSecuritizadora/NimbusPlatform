<?php

namespace App\Http\Controllers\Site;

use App\Enums\VacancyDepartment;
use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyWorkModel;
use App\Http\Controllers\Controller;
use App\Jobs\ScanFileForMalware;
use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\Vacancy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    /**
     * Display a listing of active vacancies with filters and pagination.
     */
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'work_model' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Vacancy::query()->visibleForSite()->latest('published_at')->latest('id');

        if (filled($validated['q'] ?? null)) {
            $query->where('title', 'like', '%'.trim($validated['q']).'%');
        }

        if (filled($validated['department'] ?? null)) {
            $query->where('department', $validated['department']);
        }

        if (filled($validated['type'] ?? null)) {
            $query->where('type', $validated['type']);
        }

        if (filled($validated['work_model'] ?? null)) {
            $query->where('work_model', $validated['work_model']);
        }

        if (filled($validated['location'] ?? null)) {
            $query->where('location', 'like', '%'.trim($validated['location']).'%');
        }

        $vacancies = $query->paginate(12)->withQueryString();

        $departments = VacancyDepartment::options();
        $types = VacancyEmploymentType::options();
        $workModels = VacancyWorkModel::options();

        $locations = Vacancy::query()
            ->visibleForSite()
            ->whereNotNull('location')
            ->distinct()
            ->orderBy('location')
            ->pluck('location')
            ->all();

        $filters = [
            'q' => $validated['q'] ?? null,
            'department' => $validated['department'] ?? null,
            'type' => $validated['type'] ?? null,
            'work_model' => $validated['work_model'] ?? null,
            'location' => $validated['location'] ?? null,
        ];

        $hasFilters = collect($filters)->filter(fn ($v): bool => filled($v))->isNotEmpty();

        return view('site.vacancies.index', compact('vacancies', 'departments', 'types', 'workModels', 'locations', 'filters', 'hasFilters'));
    }

    /**
     * Display the specified vacancy and application form.
     */
    public function show(string $slug): View
    {
        $vacancy = Vacancy::query()->visibleForSite()->where('slug', $slug)->firstOrFail();

        return view('site.vacancies.show', compact('vacancy'));
    }

    /**
     * Handle the application submission.
     */
    public function apply(Request $request, string $vacancy): RedirectResponse
    {
        // Honeypot: hidden field `website` must be empty
        if (filled($request->input('website'))) {
            return back()->with('success', 'Sua candidatura foi enviada com sucesso! Agradecemos o interesse em fazer parte da equipe BSI Capital.');
        }

        $vacancy = Vacancy::query()
            ->visibleForSite()
            ->where('slug', $vacancy)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'resume' => ['required', 'file', 'mimes:pdf,doc,docx', 'extensions:pdf,doc,docx', 'max:10240'],
            'message' => ['nullable', 'string', 'max:2000'],
            'lgpd_consent' => ['required', 'accepted'],
            'website' => ['nullable', 'string', 'max:255'],
        ], [
            'lgpd_consent.accepted' => 'É necessário concordar com o tratamento de dados para prosseguir.',
        ]);

        $emailLower = mb_strtolower(trim($validated['email']));
        $windowDays = (int) config('privacy.recruitment.duplicate_window_days', 30);

        if ($windowDays > 0) {
            $recentDuplicate = JobApplication::query()
                ->where('vacancy_id', $vacancy->id)
                ->whereRaw('LOWER(email) = ?', [$emailLower])
                ->where('created_at', '>=', now()->subDays($windowDays))
                ->exists();

            if ($recentDuplicate) {
                return back()
                    ->withErrors(['email' => 'Já recebemos uma candidatura com este e-mail para esta vaga recentemente. Caso queira complementar informações, aguarde o retorno da equipe.'])
                    ->withInput();
            }
        }

        $resumePath = $request->file('resume')->store('', 'resumes');

        $jobApplication = JobApplication::create([
            'vacancy_id' => $vacancy->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'linkedin_url' => $validated['linkedin_url'] ?? null,
            'resume_path' => $resumePath,
            'message' => $validated['message'] ?? null,
            'status' => JobApplication::STATUS_NEW,
        ]);

        // Create initial history
        JobApplicationStatusHistory::create([
            'job_application_id' => $jobApplication->id,
            'from_status' => null,
            'to_status' => JobApplication::STATUS_NEW,
            'changed_by_user_id' => null,
            'note' => null,
            'created_at' => $jobApplication->created_at,
            'updated_at' => $jobApplication->created_at,
        ]);

        ScanFileForMalware::dispatch(
            'resumes',
            $resumePath,
            "job-application:{$jobApplication->id}",
            $jobApplication,
        )->afterCommit();

        return back()->with('success', 'Sua candidatura foi enviada com sucesso! Agradecemos o interesse em fazer parte da equipe BSI Capital.');
    }
}
