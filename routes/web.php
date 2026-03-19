<?php

use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PublicDocumentsController;
use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('site.home');

Route::get('/servicos', [SiteController::class, 'services'])->name('site.services');
Route::get('/sobre', [SiteController::class, 'about'])->name('site.about');
Route::get('/governanca', [SiteController::class, 'governance'])->name('site.governance');
Route::get('/contato', [SiteController::class, 'contact'])->name('site.contact');

Route::get('/emissoes', [SiteController::class, 'emissions'])->name('site.emissions');
Route::get('/ri', [SiteController::class, 'ri'])->name('site.ri');

Route::get('/documentos-publicos', [PublicDocumentsController::class, 'index'])
    ->name('public-documents');

// Healthcheck para staging / monitoramento
Route::get('/healthcheck', function () {
    $checks = [
        'app' => true,
        'database' => false,
        'storage' => false,
    ];

    try {
        \Illuminate\Support\Facades\DB::select('SELECT 1');
        $checks['database'] = true;
    } catch (\Throwable) {
    }

    try {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));
        $disk->put('healthcheck.txt', 'ok');
        $disk->delete('healthcheck.txt');
        $checks['storage'] = true;
    } catch (\Throwable) {
    }

    $healthy = ! in_array(false, $checks, true);

    return response()->json([
        'status' => $healthy ? 'ok' : 'degraded',
        'checks' => $checks,
        'timestamp' => now()->toIso8601String(),
    ], $healthy ? 200 : 503);
})->name('healthcheck');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/investor.php';
