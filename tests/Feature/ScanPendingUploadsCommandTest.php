<?php

use App\Enums\MalwareScanStatus;
use App\Jobs\ScanFileForMalware;
use App\Models\JobApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches only pending files for antivirus scanning', function () {
    Queue::fake();

    $pendingApplication = JobApplication::factory()->create([
        'scan_status' => MalwareScanStatus::Pending,
    ]);

    JobApplication::factory()->create([
        'scan_status' => MalwareScanStatus::Clean,
    ]);

    $this->artisan('uploads:scan-pending')
        ->expectsOutput('1 arquivo(s) pendente(s) enviado(s) para varredura.')
        ->assertSuccessful();

    Queue::assertPushed(
        ScanFileForMalware::class,
        fn (ScanFileForMalware $job): bool => $job->fileRecord?->is($pendingApplication) === true,
    );
    Queue::assertPushed(ScanFileForMalware::class, 1);
});
