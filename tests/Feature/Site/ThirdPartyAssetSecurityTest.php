<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

it('does not load scripts or styles from disallowed CDNs in Blade views', function () {
    $bladeFilesWithExternalCdnReferences = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => Str::endsWith($file->getFilename(), '.blade.php'))
        ->filter(function (SplFileInfo $file): bool {
            return Str::contains(File::get($file->getPathname()), [
                'cdn.jsdelivr.net',
                'fonts.bunny.net',
                'unpkg.com',
            ]);
        })
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($bladeFilesWithExternalCdnReferences)->toBeEmpty();
});

it('pins third party browser libraries to exact package versions', function () {
    $package = json_decode(
        File::get(base_path('package.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($package['dependencies'])->toMatchArray([
        'bootstrap' => '5.3.8',
        'bootstrap-icons' => '1.13.1',
        'chart.js' => '4.5.1',
        'imask' => '7.6.1',
    ]);
});

it('serves shared public site dependencies from the local Vite bundle', function () {
    $this->get(route('site.home'))
        ->assertSuccessful()
        ->assertSee(Vite::asset('resources/css/site-vendor.css'), false)
        ->assertSee(Vite::asset('resources/js/site-vendor.js'), false)
        ->assertDontSee('cdn.jsdelivr.net', false)
        ->assertDontSee('unpkg.com', false);
});
