<?php

namespace App\Jobs;

use App\Mail\JobApplicationStatusMail;
use App\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendJobApplicationStatusMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public JobApplication $application) {}

    public function handle(): void
    {
        $application = $this->application->fresh(['vacancy']);

        if (! $application || ! in_array($application->status, [JobApplication::STATUS_HIRED, JobApplication::STATUS_REJECTED], true)) {
            return;
        }

        if (blank($application->email)) {
            return;
        }

        Mail::to($application->email)->send(new JobApplicationStatusMail($application));
    }
}
