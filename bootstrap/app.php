<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
            \App\Http\Middleware\SetSecurityHeaders::class,
        ]);

        $middleware->alias([
            'approved' => \App\Http\Middleware\EnsureUserIsApproved::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
