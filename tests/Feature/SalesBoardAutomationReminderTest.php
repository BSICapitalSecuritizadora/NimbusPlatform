<?php

use App\Enums\SalesBoardAutomationAlertType;
use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Notifications\SalesBoardAutomationNotification;
use App\Services\SalesBoards\SalesBoardAutomationAlertDispatcher;
use App\Services\SalesBoards\SalesBoardAutomationRecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    AutomationFixture::disable();
    Notification::fake();
});

/**
 * Um resolvedor de teste: o de produção devolve vazio de propósito, porque o
 * projeto ainda não define responsável pelo Quadro de Vendas.
 */
function resolveRecipientsTo(User ...$users): void
{
    app()->instance(SalesBoardAutomationRecipientResolver::class, new class(array_values($users)) implements SalesBoardAutomationRecipientResolver
    {
        /** @param list<User> $users */
        public function __construct(private readonly array $users) {}

        public function forGenerationBlocked($target): array
        {
            return $this->users;
        }

        public function forGenerationFailed($target): array
        {
            return $this->users;
        }

        public function forBuilderHandoff($cycle): array
        {
            return $this->users;
        }

        public function forBuilderReminder($review): array
        {
            return $this->users;
        }

        public function forManagementReminder($cycle): array
        {
            return $this->users;
        }
    });
}

it('sends nothing while no threshold is configured', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    resolveRecipientsTo(User::factory()->create());

    AutomationFixture::run();

    // Sem SLA definido, o motor existe e fica calado. Inventar "3 dias" aqui
    // seria criar requisito de negócio dentro de um arquivo de configuração.
    expect(SalesBoardAutomationAlert::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('sends a blocked reminder once the configured threshold is crossed', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    $recipient = User::factory()->create();
    resolveRecipientsTo($recipient);

    config()->set('sales_board.automation.reminders.blocked_after_days', 2);

    AutomationFixture::run();

    // Bloqueado agora mesmo: ainda não cruzou o limiar.
    expect(SalesBoardAutomationAlert::query()->count())->toBe(0);

    $this->travel(3)->days();

    $run = AutomationFixture::run();

    $alert = SalesBoardAutomationAlert::query()->sole();

    expect($alert->alert_type)->toBe(SalesBoardAutomationAlertType::GenerationBlocked)
        ->and($alert->recipient_user_id)->toBe($recipient->id)
        ->and($alert->sent_at)->not->toBeNull()
        ->and($run->alerts_sent)->toBe(1);

    Notification::assertSentTo($recipient, SalesBoardAutomationNotification::class);
});

it('never repeats the same alert within the same window', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    $recipient = User::factory()->create();
    resolveRecipientsTo($recipient);

    config()->set('sales_board.automation.reminders.blocked_after_days', 2);

    AutomationFixture::run();
    $this->travel(3)->days();

    AutomationFixture::run();
    $second = AutomationFixture::run();
    $third = AutomationFixture::run();

    // O scheduler roda de hora em hora; o alerta sai uma vez.
    expect(SalesBoardAutomationAlert::query()->count())->toBe(1)
        ->and($second->alerts_sent)->toBe(0)
        ->and($second->alerts_deduped)->toBe(1)
        ->and($third->alerts_deduped)->toBe(1);

    Notification::assertSentTimes(SalesBoardAutomationNotification::class, 1);
});

it('does not fail the run when nobody can be resolved', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);

    config()->set('sales_board.automation.reminders.blocked_after_days', 0);

    // O resolvedor de produção: vazio, porque não há responsável definido.
    $run = AutomationFixture::run();

    expect($run->status->isTechnicallyHealthy())->toBeTrue()
        ->and($run->alerts_sent)->toBe(0)
        ->and(SalesBoardAutomationAlert::query()->count())->toBe(0)
        // E o alvo continua tendo sido processado normalmente.
        ->and(SalesBoardAutomationTarget::query()->sole()->attempt_count)->toBe(1);

    Notification::assertNothingSent();
});

it('reminds about a builder review that stayed open', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    AutomationFixture::enable([$scenario['construction']], '2026-07-01');
    $recipient = User::factory()->create();
    resolveRecipientsTo($recipient);

    config()->set('sales_board.automation.reminders.builder_review_after_days', 3);

    // A revisão enviada é imutável na Fase D -- e deve continuar sendo. Quem
    // anda é o relógio, não o registro.
    $this->travel(4)->days();

    AutomationFixture::run('2026-08-13');

    $alert = SalesBoardAutomationAlert::query()->sole();

    expect($alert->alert_type)->toBe(SalesBoardAutomationAlertType::BuilderReminder)
        ->and($alert->sales_board_builder_review_id)->toBe($review->id)
        // O lembrete não toca na revisão.
        ->and($review->fresh()->status->value)->toBe('em_andamento')
        ->and($review->fresh()->sections->pluck('status')->pluck('value')->unique()->all())
        ->toBe(['pendente']);
});

it('escalates instead of reminding once the longer threshold is crossed', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    AutomationFixture::enable([$scenario['construction']], '2026-07-01');
    resolveRecipientsTo(User::factory()->create());

    config()->set('sales_board.automation.reminders.builder_review_after_days', 3);
    config()->set('sales_board.automation.reminders.builder_review_escalation_after_days', 7);

    $this->travel(9)->days();

    AutomationFixture::run('2026-08-13');

    expect(SalesBoardAutomationAlert::query()->sole()->alert_type)
        ->toBe(SalesBoardAutomationAlertType::BuilderEscalation);
});

it('reminds about a competence waiting on management without deciding anything', function () {
    $scenario = ManagementReviewFixture::submittedCycle();

    AutomationFixture::enable([$scenario['construction']], '2026-07-01');
    resolveRecipientsTo(User::factory()->create());

    config()->set('sales_board.automation.reminders.management_review_after_days', 0);

    AutomationFixture::run('2026-08-13');

    expect(SalesBoardAutomationAlert::query()->sole()->alert_type)
        ->toBe(SalesBoardAutomationAlertType::ManagementReminder)
        ->and($scenario['cycle']->fresh()->status->value)->toBe('analise_gestao')
        ->and(SalesBoardManagementReview::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0);
});

it('announces a cycle that is apurado and still not handed over', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    resolveRecipientsTo(User::factory()->create());

    config()->set('sales_board.automation.reminders.ready_for_builder_after_days', 0);

    AutomationFixture::run();

    expect(SalesBoardAutomationAlert::query()->sole()->alert_type)
        ->toBe(SalesBoardAutomationAlertType::ReadyForBuilder)
        ->and(SalesBoardBuilderReview::query()->count())->toBe(0);
});

it('removes the ledger row when the notification fails, so the next run retries', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $recipient = User::factory()->create();
    $dispatcher = app(SalesBoardAutomationAlertDispatcher::class);

    // Um destinatário cujo canal estoura no envio.
    $exploding = new class extends User
    {
        public function notify($instance): void
        {
            throw new RuntimeException('canal indisponível');
        }
    };
    $exploding->exists = true;
    $exploding->id = $recipient->id;

    $dispatcher->dispatch(
        SalesBoardAutomationAlertType::GenerationBlocked,
        [$exploding],
        ['sales_board_automation_target_id' => null],
        '2026-09-13',
        'Empreendimento',
        '08/2026',
    );

    // A linha é retirada para que a próxima execução tente de novo -- manter o
    // registro faria o aviso ser dado por enviado para sempre.
    expect(SalesBoardAutomationAlert::query()->count())->toBe(0)
        ->and($dispatcher->counters()['failed'])->toBe(1)
        ->and($dispatcher->counters()['sent'])->toBe(0);
});

it('counts an alert with no resolvable recipient without sending anything', function () {
    $dispatcher = app(SalesBoardAutomationAlertDispatcher::class);

    $dispatcher->dispatch(
        SalesBoardAutomationAlertType::GenerationBlocked,
        [],
        ['sales_board_automation_target_id' => null],
        '2026-09-13',
        'Empreendimento',
        '08/2026',
    );

    expect($dispatcher->counters()['without_recipient'])->toBe(1)
        ->and($dispatcher->counters()['sent'])->toBe(0)
        ->and(SalesBoardAutomationAlert::query()->count())->toBe(0);

    Notification::assertNothingSent();
});
