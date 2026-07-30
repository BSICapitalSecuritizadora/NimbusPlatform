<?php

namespace App\Console\Commands;

use App\Enums\MalwareScanStatus;
use App\Jobs\ScanFileForMalware;
use App\Models\JobApplication;
use App\Models\Nimbus\SubmissionFile;
use App\Models\ProposalFile;
use App\Services\DocumentStorageService;
use Illuminate\Console\Command;

class ScanPendingUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'uploads:scan-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enfileira a varredura antivírus de arquivos pendentes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dispatchedJobs = 0;

        ProposalFile::query()
            ->where('scan_status', MalwareScanStatus::Pending->value)
            ->eachById(function (ProposalFile $file) use (&$dispatchedJobs): void {
                ScanFileForMalware::dispatch(
                    $file->disk,
                    $file->file_path,
                    "proposal:{$file->proposal_id}",
                    $file,
                );

                $dispatchedJobs++;
            });

        SubmissionFile::query()
            ->where('scan_status', MalwareScanStatus::Pending->value)
            ->eachById(function (SubmissionFile $file) use (&$dispatchedJobs): void {
                ScanFileForMalware::dispatch(
                    DocumentStorageService::privateDisk(),
                    $file->storage_path,
                    "submission:{$file->nimbus_submission_id}",
                    $file,
                );

                $dispatchedJobs++;
            });

        JobApplication::query()
            ->where('scan_status', MalwareScanStatus::Pending->value)
            ->eachById(function (JobApplication $application) use (&$dispatchedJobs): void {
                ScanFileForMalware::dispatch(
                    'resumes',
                    $application->resume_path,
                    "job-application:{$application->id}",
                    $application,
                );

                $dispatchedJobs++;
            });

        $this->info("{$dispatchedJobs} arquivo(s) pendente(s) enviado(s) para varredura.");

        return self::SUCCESS;
    }
}
