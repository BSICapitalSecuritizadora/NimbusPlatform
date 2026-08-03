<?php

use App\Http\Middleware\EnsureLivewirePreviewIsSafe;
use App\Http\Middleware\EnsureUserIsApproved;
use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // H-7: scope to explicit proxy IPs via env; set TRUSTED_PROXIES=* on Azure App Service
        $middleware->trustProxies(at: (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'));

        // H-1: security headers on every web response
        $middleware->web(prepend: [
            SetSecurityHeaders::class,
        ]);

        $middleware->web(append: [
            EnsureLivewirePreviewIsSafe::class,
        ]);

        $middleware->alias([
            'approved' => EnsureUserIsApproved::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->is('gestao-documental-externa/submissions')) {
                return redirect()->route('nimbus.submissions.create', [
                    'upload_error' => 'too-large',
                ]);
            }

            return null;
        });
    })->create();
