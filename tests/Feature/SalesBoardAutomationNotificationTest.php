<?php

use App\Enums\SalesBoardAutomationAlertType;
use App\Filament\Resources\SalesBoardAutomationTargets\SalesBoardAutomationTargetResource;
use App\Models\SalesBoardAutomationAlert;
use App\Models\User;
use App\Notifications\SalesBoardAutomationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\FixedSalesBoardAutomationRecipientResolver;

/**
 * O aviso da automação como quem o recebe o vê.
 *
 * Vai pela fila -- o SMTP não pode segurar o tick do scheduler --, aparece no
 * sino do painel e leva à tela em que a ação acontece.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    AutomationFixture::disable();
    config()->set('mail.default', 'array');
});

it('goes through the queue instead of sending mail inside the scheduled command', function () {
    Queue::fake();

    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    FixedSalesBoardAutomationRecipientResolver::bind(User::factory()->create());
    config()->set('sales_board.automation.reminders.blocked_after_days', 0);

    $run = AutomationFixture::run();

    expect(new SalesBoardAutomationNotification(SalesBoardAutomationAlertType::GenerationBlocked, 'X', '08/2026'))
        ->toBeInstanceOf(ShouldQueue::class)
        ->and($run->alerts_sent)->toBe(1);

    // Um job por canal, e nenhum envio feito dentro do comando.
    Queue::assertPushed(SendQueuedNotifications::class, 2);
});

it('shows up in the panel bell and links to the automation screen', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    $recipient = User::factory()->create();
    FixedSalesBoardAutomationRecipientResolver::bind($recipient);
    config()->set('sales_board.automation.reminders.blocked_after_days', 0);

    AutomationFixture::run();

    // O sino do Filament só lista notificações no formato dele.
    $stored = $recipient->notifications()->where('data->format', 'filament')->sole();
    $url = SalesBoardAutomationTargetResource::getUrl('index', panel: 'admin');

    expect($stored->data['title'])->toContain('Geração bloqueada pela fonte')
        ->and($stored->data['body'])->toContain('08/2026')
        ->and($stored->data['actions'][0]['url'])->toBe($url);
});

it('carries a link in the e-mail', function () {
    $notification = new SalesBoardAutomationNotification(
        SalesBoardAutomationAlertType::ManagementReminder,
        'Residencial Alfa',
        '08/2026',
        'Em análise da Gestão há 3 dia(s).',
        'https://nimbus.test/admin/sales-board-cycles/7',
    );

    $mail = $notification->toMail(User::factory()->make());

    expect($mail->actionUrl)->toBe('https://nimbus.test/admin/sales-board-cycles/7')
        ->and($mail->subject)->toBe('Quadro de Vendas — Análise da Gestão pendente (08/2026)')
        ->and(implode(' ', $mail->introLines))->toContain('Residencial Alfa — competência 08/2026.');
});

it('returns the alert to the ledger when the queued delivery fails', function () {
    $key = hash('sha256', 'aviso-que-nao-saiu');
    SalesBoardAutomationAlert::factory()->create(['dedupe_key' => $key]);

    $notification = new SalesBoardAutomationNotification(
        SalesBoardAutomationAlertType::GenerationBlocked,
        'Residencial Alfa',
        '08/2026',
        dedupeKey: $key,
    );

    $notification->failed(new RuntimeException('SMTP indisponível'));

    // Sem a linha, a execução seguinte volta a considerar o aviso devido.
    expect(SalesBoardAutomationAlert::query()->where('dedupe_key', $key)->exists())->toBeFalse();
});

/**
 * O worker da fila, simulado: entrega cada job enfileirado, menos o do canal que
 * deve falhar -- esse cai, e o job chama o `failed()` da notificação, como o
 * worker faz depois da última tentativa.
 */
function workQueuedAutomationAlerts(?string $failingChannel = null): void
{
    foreach (Queue::pushed(SendQueuedNotifications::class) as $job) {
        if ($job->channels === [$failingChannel]) {
            $job->failed(new RuntimeException('Entrega recusada.'));

            continue;
        }

        $job->handle(app(ChannelManager::class));
    }
}

function deliveredAutomationEmails(): int
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()->count();
}

it('redelivers only the channel whose delivery failed', function (string $failingChannel, string $deliveredChannel) {
    Queue::fake();

    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    $recipient = User::factory()->create();
    FixedSalesBoardAutomationRecipientResolver::bind($recipient);
    config()->set('sales_board.automation.reminders.blocked_after_days', 0);

    AutomationFixture::run();
    workQueuedAutomationAlerts($failingChannel);

    // O canal que entregou continua registrado; só o que falhou volta a ser devido.
    expect(SalesBoardAutomationAlert::query()->pluck('channel')->all())->toBe([$deliveredChannel]);

    Queue::fake();
    $this->travel(1)->hour();

    AutomationFixture::run();

    $redelivered = Queue::pushed(SendQueuedNotifications::class);

    // Antes: a falha de um canal devolvia o aviso inteiro, e o outro canal saía de novo a cada hora.
    expect($redelivered)->toHaveCount(1)
        ->and($redelivered->sole()->channels)->toBe([$failingChannel]);

    workQueuedAutomationAlerts();

    expect($recipient->notifications()->count())->toBe(1)
        ->and(deliveredAutomationEmails())->toBe(1)
        ->and(SalesBoardAutomationAlert::query()->orderBy('channel')->pluck('channel')->all())->toBe(['database', 'mail']);

    // Com os dois canais entregues, a janela está encerrada para este aviso.
    Queue::fake();
    $this->travel(1)->hour();

    AutomationFixture::run();

    Queue::assertNothingPushed();
})->with([
    'e-mail recusado pelo SMTP' => ['mail', 'database'],
    'sino que não gravou' => ['database', 'mail'],
]);
