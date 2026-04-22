<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// NimbusDocs Scheduled Tasks
\Illuminate\Support\Facades\Schedule::call(function () {
    // Delete tokens that have been expired for more than 24 hours
    \App\Models\Nimbus\AccessToken::where('status', 'expired')
        ->orWhere('expires_at', '<', now()->subHours(24))
        ->delete();
})->dailyAt('03:00')->name('nimbus-tokens-cleanup');

\Illuminate\Support\Facades\Schedule::command('app:cleanup-temporary-uploads')
    ->dailyAt('02:00')
    ->name('cleanup-temporary-uploads');

\Illuminate\Support\Facades\Schedule::command('app:snapshot-monthly-fund-balances')
    ->monthlyOn(1, '00:05')
    ->name('fund-balances-monthly-snapshot');
