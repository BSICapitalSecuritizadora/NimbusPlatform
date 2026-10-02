<?php

use App\Filament\Resources\SalesBoardAutomationTargets\Pages\ListSalesBoardAutomationTargets;
use App\Jobs\RecordQueueHeartbeat;
use App\Support\Operations\ProcessHeartbeat;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\AutomationFixture;

/**
 * O sinal de vida do agendador e da fila.
 *
 * Com o interruptor da automação desligado nenhuma execução é registrada, e o
 * aviso de execução interrompida depende de uma execução seguinte acontecer:
 * nada provava que o agendador estava de pé. O agendador grava a própria batida
 * a cada minuto; a da fila só é gravada quando um worker executa o job que o
 * agendador despacha a cada cinco minutos.
 */
uses(RefreshDatabase::class);

function heartbeatScheduledEvent(string $name): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => $event->description === $name);
}

it('registers the scheduler heartbeat every minute and the queue heartbeat every five minutes', function () {
    expect(heartbeatScheduledEvent('scheduler-heartbeat')?->expression)->toBe('* * * * *')
        ->and(heartbeatScheduledEvent('queue-heartbeat')?->expression)->toBe('*/5 * * * *');
});

it('records the scheduler beat when the scheduled callback runs', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:32:00', 'UTC'));

    expect(ProcessHeartbeat::schedulerLastSeen())->toBeNull();

    heartbeatScheduledEvent('scheduler-heartbeat')->run(app());

    expect(ProcessHeartbeat::schedulerLastSeen()?->toIso8601String())->toBe('2026-09-13T14:32:00+00:00');
});

it('records the queue beat only when a worker runs the job', function () {
    Queue::fake([RecordQueueHeartbeat::class]);

    heartbeatScheduledEvent('queue-heartbeat')->run(app());

    // Despachar não é executar: sem worker, nada é gravado.
    Queue::assertPushed(RecordQueueHeartbeat::class);
    expect(ProcessHeartbeat::queueLastSeen())->toBeNull()
        ->and(new RecordQueueHeartbeat)->toBeInstanceOf(ShouldBeUnique::class);

    (new RecordQueueHeartbeat)->handle();

    expect(ProcessHeartbeat::queueLastSeen())->not->toBeNull();
});

it('describes a quiet queue while the scheduler is alive, and an unreadable cache as unavailable', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:32:00', 'UTC'));

    Cache::forever(ProcessHeartbeat::SCHEDULER_KEY, now()->subMinute()->toIso8601String());
    Cache::forever(ProcessHeartbeat::QUEUE_KEY, now()->subMinutes(30)->toIso8601String());

    expect(ProcessHeartbeat::describe())->toBe([
        ['text' => 'Agendador: último sinal às 11:31 (há 1 min).', 'healthy' => true],
        [
            'text' => 'Sem sinal da fila desde 11:02 (há 30 min): avisos por e-mail e no sino ficam retidos até o processo queue:work voltar.',
            'healthy' => false,
        ],
    ]);

    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache fora do ar'));

    expect(ProcessHeartbeat::describe()[0])->toBe([
        'text' => 'Agendador: sinal indisponível — não foi possível ler o cache agora.',
        'healthy' => null,
    ]);
});

it('tells on the automation screen that the scheduler went quiet', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
    AutomationFixture::disable();

    // 14:32 em UTC; 11:32 em Brasília, como a tela mostra.
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:32:00', 'UTC'));

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Agendador: nenhum sinal de vida registrado ainda.');

    Cache::forever(ProcessHeartbeat::SCHEDULER_KEY, now()->subMinutes(20)->toIso8601String());

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Sem sinal do agendador desde 11:12 (há 20 min): nenhuma tarefa agendada está rodando — nem a automação, nem os lembretes.')
        ->assertSee('Verifique o processo schedule:work do App Service.')
        ->assertSee('Fila: sem medição enquanto o agendador não estiver rodando');

    Cache::forever(ProcessHeartbeat::SCHEDULER_KEY, now()->subMinute()->toIso8601String());

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Agendador: último sinal às 11:31 (há 1 min).')
        ->assertSee('Fila: nenhum sinal de vida registrado ainda.')
        ->assertDontSee('Sem sinal do agendador');
});

it('shows the heartbeat even with the automation switched off', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
    AutomationFixture::disable();

    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:32:00', 'UTC'));
    ProcessHeartbeat::recordScheduler();
    ProcessHeartbeat::recordQueue();

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Automação desligada no interruptor global')
        ->assertSee('Agendador: último sinal às 11:32 (há 0 min).')
        ->assertSee('Fila: último sinal às 11:32 (há 0 min).');
});
