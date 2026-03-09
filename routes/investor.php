<?php

use App\Http\Controllers\Investor\Auth\InvestorAuthController;
use App\Http\Controllers\Investor\InvestorDashboardController;
use App\Http\Controllers\Investor\InvestorDocumentsController;
use App\Http\Controllers\Investor\InvestorEmissionsController;
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
        Route::get('/', [InvestorDashboardController::class, 'index'])->name('dashboard');

        Route::get('/emissoes', [InvestorEmissionsController::class, 'index'])->name('emissions');
        Route::get('/documentos', [InvestorDocumentsController::class, 'index'])->name('documents');

        Route::get('/documentos/{document}/download', [InvestorDocumentsController::class, 'download'])
            ->name('documents.download')
            ->middleware('throttle:60,1');
    });
});
