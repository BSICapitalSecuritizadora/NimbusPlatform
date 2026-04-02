<?php

use App\Http\Controllers\Investor\Auth\InvestorAuthController;
use App\Livewire\Investor\DocumentList;
use App\Livewire\Investor\InvestorDashboard;
use App\Livewire\Investor\InvestorEmissions;
use Illuminate\Support\Facades\Route;

Route::prefix('investidor')->name('investor.')->group(function () {

    // Auth
    Route::middleware('guest:investor')->group(function () {
        Route::get('/login', [InvestorAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [InvestorAuthController::class, 'login'])
            ->name('login.post')
            ->middleware('throttle:10,1');
    });

    Route::post('/logout', [InvestorAuthController::class, 'logout'])
        ->middleware('auth:investor')
        ->name('logout');

    // Portal (protegido)
    Route::middleware('auth:investor')->group(function () {
        Route::get('/', InvestorDashboard::class)->name('dashboard');

        Route::get('/emissoes', InvestorEmissions::class)->name('emissions');
        Route::get('/documentos', DocumentList::class)->name('documents');

        Route::get('/documentos/{document}/download', \App\Http\Controllers\Portal\DocumentDownloadController::class)
            ->name('documents.download')
            ->middleware('throttle:60,1');
    });
});
