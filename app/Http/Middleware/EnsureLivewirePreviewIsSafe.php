<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Symfony\Component\HttpFoundation\Response;

class EnsureLivewirePreviewIsSafe
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('livewire.preview-file')) {
            return $next($request);
        }

        $filename = (string) $request->route('filename');
        $extension = Str::of($filename)->afterLast('.')->lower()->toString();
        $allowedPreviewExtensions = (array) config('livewire.temporary_file_upload.preview_mimes', []);

        abort_unless(in_array($extension, $allowedPreviewExtensions, true), 404);

        $storage = FileUploadConfiguration::storage();
        $path = FileUploadConfiguration::path($filename);

        abort_unless($storage->exists($path), 404);

        $mimeType = (string) $storage->mimeType($path);

        abort_if(in_array($mimeType, ['image/svg', 'image/svg+xml'], true), 404);

        return $next($request);
    }
}
