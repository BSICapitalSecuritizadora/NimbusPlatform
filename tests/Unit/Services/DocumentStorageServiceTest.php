<?php

use App\Services\DocumentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

it('stores private files on the local disk under the nimbus docs prefix', function () {
    Storage::set('local', Storage::createLocalDriver([
        'root' => storage_path('framework/testing/disks/local-'.uniqid()),
        'throw' => false,
    ]));

    $storedFile = app(DocumentStorageService::class)->storePrivateFile(
        UploadedFile::fake()->create('contrato-social.pdf', 128, 'application/pdf'),
        'submissions/42',
    );

    expect($storedFile['disk'])->toBe(DocumentStorageService::PRIVATE_DISK)
        ->and($storedFile['path'])->toStartWith(DocumentStorageService::PRIVATE_PREFIX.'/submissions/42/')
        ->and($storedFile['stored_name'])->toEndWith('.pdf')
        ->and($storedFile['original_name'])->toBe('contrato-social.pdf')
        ->and($storedFile['size_bytes'])->toBeGreaterThan(0)
        ->and($storedFile['checksum'])->not->toBeNull();

    Storage::disk('local')->assertExists($storedFile['path']);

    $metadata = app(DocumentStorageService::class)->metadata($storedFile['path']);

    expect($metadata['mime_type'])->toBe('application/pdf')
        ->and($metadata['size_bytes'])->not->toBeNull();
});

it('rejects unsupported storage disks', function () {
    expect(fn () => app(DocumentStorageService::class)->exists('documents/demo.pdf', 's3'))
        ->toThrow(\InvalidArgumentException::class, 'Unsupported storage disk [s3].');
});
