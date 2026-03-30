<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\Vacancy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    /**
     * Display a listing of active vacancies.
     */
    public function index(): View
    {
        $vacancies = Vacancy::where('is_active', true)->latest()->get();

        return view('site.vacancies.index', compact('vacancies'));
    }

    /**
     * Display the specified vacancy and application form.
     */
    public function show(string $slug): View
    {
        $vacancy = Vacancy::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return view('site.vacancies.show', compact('vacancy'));
    }

    /**
     * Handle the application submission.
     */
    public function apply(Request $request, int $id): RedirectResponse
    {
        $vacancy = Vacancy::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'linkedin_url' => 'nullable|url|max:255',
            'resume' => 'required|file|mimes:pdf,doc,docx|max:10240', // 10MB max
            'message' => 'nullable|string|max:2000',
        ]);

        $resumePath = $request->file('resume')->store('resumes');

        JobApplication::create([
            'vacancy_id' => $vacancy->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'linkedin_url' => $validated['linkedin_url'],
            'resume_path' => $resumePath,
            'message' => $validated['message'],
            'status' => JobApplication::STATUS_NEW,
        ]);

        return back()->with('success', 'Sua candidatura foi enviada com sucesso! Agradecemos o interesse em fazer parte da equipe BSI Capital.');
    }
}
