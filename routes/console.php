<?php

use App\Jobs\ProcessNimbusNotificationOutbox;
use App\Jobs\SyncContaAzulExpensesJob;
use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\NotificationOutbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Gestão Documental Externa Scheduled Tasks
Schedule::call(function () {
    // Delete tokens that have been expired for more than 24 hours
    AccessToken::query()
        ->where('status', 'PENDING')
        ->where('expires_at', '<', now()->subHours(24))
        ->delete();
})->dailyAt('03:00')->name('nimbus-tokens-cleanup');

// Nimbus Notification Outbox — dispatch due PENDING/FAILED and stale SENDING every minute
Schedule::call(function () {
    $due = NotificationOutbox::query()
        ->where(function ($q) {
            $q->where(function ($qq) {
                $qq->where('status', 'PENDING')
                    ->where(function ($qqq) {
                        $qqq->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                    });
            })->orWhere(function ($qq) {
                $qq->where('status', 'FAILED')
                    ->whereColumn('attempts', '<', 'max_attempts')
                    ->where(function ($qqq) {
                        $qqq->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                    });
            })->orWhere(function ($qq) {
                $qq->where('status', 'SENDING')->where('updated_at', '<', now()->subMinutes(15));
            });
        })
        ->limit(100)
        ->get();

    foreach ($due as $outbox) {
        dispatch(new ProcessNimbusNotificationOutbox($outbox->id));
    }
})->everyMinute()->name('nimbus-outbox-dispatch')->withoutOverlapping();

Schedule::command('app:cleanup-temporary-uploads')
    ->dailyAt('02:00')
    ->name('cleanup-temporary-uploads');

Schedule::command('app:snapshot-monthly-fund-balances')
    ->monthlyOn(1, '00:05')
    ->name('fund-balances-monthly-snapshot');

Schedule::command('app:send-fund-minimum-balance-alerts')
    ->hourly()
    ->name('fund-minimum-balance-alerts');

Schedule::command('invitations:prune')
    ->weekly()
    ->name('prune-expired-invitations');

Schedule::command('audit:clean-filtered')
    ->dailyAt('04:00')
    ->name('audit-log-cleanup-filtered');

Artisan::command('expenses:sync-conta-azul', function () {
    $this->info('Iniciando sincronização e reconciliação de despesas com o Conta Azul...');
    dispatch_sync(new SyncContaAzulExpensesJob);
    $this->info('Sincronização e reconciliação concluídas com sucesso.');
})->purpose('Sincroniza e reconcilia despesas e históricos com a API Conta Azul');

Schedule::job(SyncContaAzulExpensesJob::class)
    ->dailyAt('06:00')
    ->name('conta-azul-expenses-sync')
    ->withoutOverlapping();

Schedule::command('obligations:generate-occurrences')
    ->dailyAt('05:45')
    ->name('obligations-generate-occurrences')
    ->withoutOverlapping();

Schedule::command('obligations:recalculate-statuses')
    ->dailyAt('06:00')
    ->name('obligations-recalculate-statuses')
    ->withoutOverlapping();

Schedule::command('obligations:send-due-notifications')
    ->dailyAt('06:15')
    ->name('obligations-send-due-notifications')
    ->withoutOverlapping();

Schedule::command('pu:queue-health --alert')
    ->everyTenMinutes()
    ->name('pu-queue-health')
    ->withoutOverlapping();

// CDI publicado (BCB/SGS): TODOS OS DIAS, consultando sempre os últimos 10 anos. Idempotente (insert-only);
// dias sem divulgação simplesmente não trazem dado novo. Enfileirado.
Schedule::command('pu:index-rates:sync --indexer=cdi --queue')
    ->dailyAt('06:30')
    ->name('pu-index-sync-cdi')
    ->withoutOverlapping();

// Após a sincronização do CDI, estende a parte realizada das curvas de PU (não homologadas) com o
// índice recém-publicado. Curvas homologadas são preservadas; curvas já completas são ignoradas.
Schedule::command('pu:curves:generate-realized')
    ->dailyAt('07:15')
    ->name('pu-curves-generate-realized')
    ->withoutOverlapping();

// IPCA publicado (BCB/SGS): todo dia 2 de cada mês, consultando sempre os últimos 10 anos. Idempotente.
Schedule::command('pu:index-rates:sync --indexer=ipca --queue')
    ->monthlyOn(2, '06:45')
    ->name('pu-index-sync-ipca')
    ->withoutOverlapping();

Schedule::command('proposals:check-stale')
    ->dailyAt('07:00')
    ->name('proposals-check-stale')
    ->withoutOverlapping();

// Retenção de dados pessoais (LGPD art. 15 e 16). Os prazos ficam em
// config/privacy.php; rodam mensalmente porque a eliminação é por idade do
// registro, não por evento — cadência diária só geraria ruído no log.
Schedule::command('vacancies:auto-close')
    ->dailyAt('01:00')
    ->name('vacancies-auto-close')
    ->withoutOverlapping();

Schedule::command('lgpd:purge-job-applications')
    ->monthlyOn(1, '01:05')
    ->name('lgpd-purge-job-applications')
    ->withoutOverlapping();

Schedule::command('lgpd:purge-contact-messages')
    ->monthlyOn(1, '01:15')
    ->name('lgpd-purge-contact-messages')
    ->withoutOverlapping();

Schedule::command('measurements:evaluate-sla')
    ->hourlyAt(15)
    ->name('measurements-evaluate-sla')
    ->withoutOverlapping();

Schedule::command('delegations:warn-expiring')
    ->dailyAt('08:00')
    ->name('delegations-warn-expiring')
    ->withoutOverlapping();
