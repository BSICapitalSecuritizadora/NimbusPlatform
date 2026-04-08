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

    $service = app(DocumentStorageService::class);
    $metadata = $service->privateMetadata($storedFile['path']);

    expect($metadata['mime_type'])->toBe('application/pdf')
        ->and($metadata['size_bytes'])->not->toBeNull()
        ->and($service->privateExists($storedFile['path']))->toBeTrue()
        ->and($service->absolutePrivatePath($storedFile['path']))->toContain('nimbus_docs');
});

it('normalizes private directories without ever escaping the private prefix', function () {
    $service = app(DocumentStorageService::class);

    expect($service->privateDirectoryPath('submissions/42'))->toBe('nimbus_docs/submissions/42')
        ->and($service->privateDirectoryPath('/submissions/42/'))->toBe('nimbus_docs/submissions/42')
        ->and($service->privateDirectoryPath('nimbus_docs/submissions/42'))->toBe('nimbus_docs/submissions/42')
        ->and($service->privateDirectoryPath(''))->toBe('nimbus_docs');
});

it('rejects unsupported storage disks', function () {
    expect(fn () => app(DocumentStorageService::class)->exists('documents/demo.pdf', 's3'))
        ->toThrow(\InvalidArgumentException::class, 'Unsupported storage disk [s3].');
});
