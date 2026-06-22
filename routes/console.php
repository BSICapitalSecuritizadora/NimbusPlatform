<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Gestão Documental Externa Scheduled Tasks
\Illuminate\Support\Facades\Schedule::call(function () {
    // Delete tokens that have been expired for more than 24 hours
    \App\Models\Nimbus\AccessToken::query()
        ->where('status', 'PENDING')
        ->where('expires_at', '<', now()->subHours(24))
        ->delete();
})->dailyAt('03:00')->name('nimbus-tokens-cleanup');

\Illuminate\Support\Facades\Schedule::command('app:cleanup-temporary-uploads')
    ->dailyAt('02:00')
    ->name('cleanup-temporary-uploads');

\Illuminate\Support\Facades\Schedule::command('app:snapshot-monthly-fund-balances')
    ->monthlyOn(1, '00:05')
    ->name('fund-balances-monthly-snapshot');

\Illuminate\Support\Facades\Schedule::command('app:send-fund-minimum-balance-alerts')
    ->hourly()
    ->name('fund-minimum-balance-alerts');

\Illuminate\Support\Facades\Schedule::command('invitations:prune')
    ->weekly()
    ->name('prune-expired-invitations');

\Illuminate\Support\Facades\Schedule::command('activitylog:clean')
    ->dailyAt('04:00')
    ->name('audit-log-cleanup');

\Illuminate\Support\Facades\Schedule::job(\App\Jobs\SyncContaAzulExpensesJob::class)
    ->dailyAt('06:00')
    ->name('conta-azul-expenses-sync')
    ->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('obligations:recalculate-statuses')
    ->dailyAt('06:00')
    ->name('obligations-recalculate-statuses')
    ->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('obligations:send-due-notifications')
    ->dailyAt('06:15')
    ->name('obligations-send-due-notifications')
    ->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('pu:queue-health --alert')
    ->everyTenMinutes()
    ->name('pu-queue-health')
    ->withoutOverlapping();
