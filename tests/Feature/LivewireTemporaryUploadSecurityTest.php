<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;

beforeEach(function () {
    Storage::fake('tmp-for-tests');
});

it('rejects SVG files before storing a Livewire temporary upload', function () {
    $svg = UploadedFile::fake()->createWithContent(
        'payload.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    expect(fn () => (new FileUploadController)->validateAndStore(
        [$svg],
        FileUploadConfiguration::disk(),
    ))->toThrow(ValidationException::class);

    Storage::disk('tmp-for-tests')->assertDirectoryEmpty(FileUploadConfiguration::directory());
});

it('returns not found for signed preview URLs containing SVG content', function () {
    foreach (['malicious-preview.svg', 'disguised-preview.png'] as $filename) {
        Storage::disk('tmp-for-tests')->put(
            FileUploadConfiguration::path($filename),
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $previewUrl = URL::temporarySignedRoute(
            'livewire.preview-file',
            now()->addMinutes(5),
            ['filename' => $filename],
        );

        $this->get($previewUrl)->assertNotFound();
    }
});

it('continues serving an allowed signed image preview', function () {
    $filename = 'allowed-preview.png';
    $png = UploadedFile::fake()->image($filename);

    Storage::disk('tmp-for-tests')->put(
        FileUploadConfiguration::path($filename),
        file_get_contents($png->getRealPath()),
    );

    $previewUrl = URL::temporarySignedRoute(
        'livewire.preview-file',
        now()->addMinutes(5),
        ['filename' => $filename],
    );

    $this->get($previewUrl)
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/png');
});
