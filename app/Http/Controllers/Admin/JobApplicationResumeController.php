<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobApplicationResumeController extends Controller
{
    public function download(JobApplication $jobApplication): StreamedResponse
    {
        Gate::authorize('recruitment.applications.view');

        abort_unless(
            $jobApplication->resume_path && Storage::disk('resumes')->exists($jobApplication->resume_path),
            404,
        );

        $extension = pathinfo($jobApplication->resume_path, PATHINFO_EXTENSION);
        $filename = 'curriculo-'.str($jobApplication->name)->slug()->value().'.'.$extension;

        return Storage::disk('resumes')->download($jobApplication->resume_path, $filename);
    }
}
