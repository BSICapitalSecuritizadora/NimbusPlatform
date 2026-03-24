<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\Vacancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JobController extends Controller
{
    /**
     * Display a listing of active vacancies.
     */
    public function index()
    {
        $vacancies = Vacancy::where('is_active', true)->latest()->get();
        return view('site.vacancies.index', compact('vacancies'));
    }

    /**
     * Display the specified vacancy and application form.
     */
    public function show($slug)
    {
        $vacancy = Vacancy::where('slug', $slug)->where('is_active', true)->firstOrFail();
        return view('site.vacancies.show', compact('vacancy'));
    }

    /**
     * Handle the application submission.
     */
    public function apply(Request $request, $id)
    {
        $vacancy = Vacancy::findOrFail($id);

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
        ]);

        return back()->with('success', 'Sua candidatura foi enviada com sucesso! Agradecemos o interesse em fazer parte da equipe BSI Capital.');
    }
}
