<?php

use App\DTOs\Nimbus\StoreSubmissionFileDTO;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use App\Services\DocumentManager;
use App\Services\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::set(DocumentStorageService::PRIVATE_DISK, Storage::createLocalDriver([
        'root' => storage_path('framework/testing/disks/local-'.uniqid()),
        'throw' => false,
    ]));
});

it('successfully uploads and processes a submission file (Happy Path)', function () {
    // 1. Setup Models
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Test User',
        'email' => 'test@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-123',
        'submission_type' => 'REGISTRATION',
        'title' => 'Test Submission',
        'status' => 'PENDING',
    ]);

    // 2. Setup DTO with fake file
    $file = UploadedFile::fake()->create('happy-path.pdf', 100, 'application/pdf');

    $dto = new StoreSubmissionFileDTO(
        file: $file,
        documentType: 'BALANCE_SHEET',
        origin: 'USER',
        visibleToUser: false,
        uploadedByType: 'PORTAL_USER',
        uploadedById: $portalUser->id,
    );

    // 3. Act
    $documentManager = app(DocumentManager::class);
    $submissionFile = $documentManager->storeSubmissionFile($submission, $dto);

    // 4. Assertions
    expect($submissionFile)->toBeInstanceOf(SubmissionFile::class)
        ->and($submissionFile->document_type)->toBe('BALANCE_SHEET')
        ->and($submissionFile->original_name)->toBe('happy-path.pdf')
        ->and($submissionFile->storage_path)->toStartWith("nimbus_docs/submissions/{$submission->id}/");

    // Physical file assertion
    Storage::disk(DocumentStorageService::PRIVATE_DISK)->assertExists($submissionFile->storage_path);

    // Database assertions
    $this->assertDatabaseHas('nimbus_submission_files', [
        'id' => $submissionFile->id,
        'nimbus_submission_id' => $submission->id,
        'document_type' => 'BALANCE_SHEET',
    ]);

    $this->assertDatabaseHas('nimbus_submission_file_versions', [
        'nimbus_submission_file_id' => $submissionFile->id,
        'version' => 1,
    ]);
});

it('uploads a file with ADMIN origin to the responses directory', function () {
    // 1. Setup Models
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Test User',
        'email' => 'test@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-123',
        'submission_type' => 'REGISTRATION',
        'title' => 'Test Submission',
        'status' => 'PENDING',
    ]);

    $file = UploadedFile::fake()->create('admin-response.pdf', 100, 'application/pdf');

    $dto = new StoreSubmissionFileDTO(
        file: $file,
        documentType: 'OTHER',
        origin: 'ADMIN',
        visibleToUser: true,
        uploadedByType: 'ADMIN',
        uploadedById: 1, // Simulated admin user ID
        notes: 'Admin feedback notes',
    );

    // 2. Act
    $documentManager = app(DocumentManager::class);
    $submissionFile = $documentManager->storeSubmissionFile($submission, $dto);

    // 3. Assertions
    expect($submissionFile->origin)->toBe('ADMIN')
        ->and($submissionFile->visible_to_user)->toBeTrue()
        ->and($submissionFile->storage_path)->toContain('/responses/')
        ->and($submissionFile->storage_path)->toContain("nimbus_docs/submissions/{$submission->id}/responses/");

    Storage::disk(DocumentStorageService::PRIVATE_DISK)->assertExists($submissionFile->storage_path);

    $this->assertDatabaseHas('nimbus_submission_file_versions', [
        'nimbus_submission_file_id' => $submissionFile->id,
        'notes' => 'Admin feedback notes',
        'uploaded_by_type' => 'ADMIN',
    ]);
});

it('cleans up physical file when database transaction fails (Failure Path)', function () {
    // 1. Setup Models
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Test User',
        'email' => 'test@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-123',
        'submission_type' => 'REGISTRATION',
        'title' => 'Test Submission',
        'status' => 'PENDING',
    ]);

    $file = UploadedFile::fake()->create('failure-path.pdf', 100, 'application/pdf');

    $dto = new StoreSubmissionFileDTO(
        file: $file,
        documentType: 'BALANCE_SHEET',
        origin: 'USER',
        visibleToUser: false,
        uploadedByType: 'PORTAL_USER',
        uploadedById: $portalUser->id,
    );

    // 2. Mock DB transaction to fail
    DB::partialMock()->shouldReceive('transaction')
        ->once()
        ->andThrow(new Exception('Simulated DB Error'));

    // 3. Act
    $documentManager = app(DocumentManager::class);

    try {
        $documentManager->storeSubmissionFile($submission, $dto);
        $this->fail('Expected exception was not thrown');
    } catch (Exception $e) {
        expect($e->getMessage())->toBe('Simulated DB Error');
    }

    Mockery::close();

    // 4. Assertions
    // Ensure no files are left on the disk (it should have been cleaned up from staging)
    $files = Storage::disk(DocumentStorageService::PRIVATE_DISK)->allFiles();
    expect($files)->toBeEmpty();

    // Ensure no database records were created
    expect(SubmissionFile::count())->toBe(0);
});
