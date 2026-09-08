<?php

use App\Enums\SalesBoardAutomationAlertType;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\ConstructionUnit;
use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\User;
use App\Notifications\SalesBoardAutomationNotification;
use App\Services\SalesBoards\SalesBoardAutomationRecipientResolver;
use App\Services\SalesBoards\SalesBoardAutomationService;
use App\Services\SalesBoards\SalesBoardRolloutRecipientDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

beforeEach(fn () => Notification::fake());

it('maps every alert type to exactly one role', function () {
    $operational = [
        SalesBoardAutomationAlertType::GenerationBlocked,
        SalesBoardAutomationAlertType::GenerationFailed,
        SalesBoardAutomationAlertType::ReadyForBuilder,
        SalesBoardAutomationAlertType::BuilderReminder,
        SalesBoardAutomationAlertType::BuilderEscalation,
    ];

    foreach ($operational as $alert) {
        expect(SalesBoardRolloutRecipientRole::forAlert($alert))
            ->toBe(SalesBoardRolloutRecipientRole::Operational);
    }

    foreach ([SalesBoardAutomationAlertType::ManagementReminder, SalesBoardAutomationAlertType::ManagementEscalation] as $alert) {
        expect(SalesBoardRolloutRecipientRole::forAlert($alert))
            ->toBe(SalesBoardRolloutRecipientRole::Management);
    }
});

it('resolves operational recipients for a blocked generation', function () {
    $scenario = RolloutFixture::emission(1);
    $people = RolloutFixture::recipients($scenario['emission']);

    $target = SalesBoardAutomationTarget::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
    ]);

    $resolved = app(SalesBoardAutomationRecipientResolver::class)->forGenerationBlocked($target);

    expect(collect($resolved)->pluck('id')->all())->toBe([$people['operational']->id]);
});

it('resolves management recipients for a management reminder', function () {
    $scenario = RolloutFixture::emission(1);
    $people = RolloutFixture::recipients($scenario['emission']);

    $cycle = SalesBoardCycle::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => $scenario['constructions'][0]->id,
    ]);

    $resolved = app(SalesBoardAutomationRecipientResolver::class)->forManagementReminder($cycle);

    expect(collect($resolved)->pluck('id')->all())->toBe([$people['management']->id]);
});

it('never falls back to administrators', function () {
    $scenario = RolloutFixture::emission(1);

    // Um administrador existe, e não é destinatário de nada.
    User::factory()->create(['approved_at' => now()]);

    $target = SalesBoardAutomationTarget::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
    ]);

    expect(app(SalesBoardAutomationRecipientResolver::class)->forGenerationBlocked($target))->toBe([]);
});

it('stops notifying a recipient that became inactive', function () {
    $scenario = RolloutFixture::emission(1);
    $directory = app(SalesBoardRolloutRecipientDirectory::class);

    $active = RolloutFixture::operationalUser();
    $leaving = RolloutFixture::operationalUser();

    $directory->add($scenario['emission'], SalesBoardRolloutRecipientRole::Operational, $active, null);
    $directory->add($scenario['emission'], SalesBoardRolloutRecipientRole::Operational, $leaving, null);

    $target = SalesBoardAutomationTarget::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
    ]);

    expect(app(SalesBoardAutomationRecipientResolver::class)->forGenerationBlocked($target))->toHaveCount(2);

    // A conta é desativada. A configuração continua; a resolução não.
    $leaving->forceFill(['approved_at' => null])->save();

    $resolved = app(SalesBoardAutomationRecipientResolver::class)->forGenerationBlocked($target);

    expect(collect($resolved)->pluck('id')->all())->toBe([$active->id])
        ->and(SalesBoardRolloutRecipient::query()->count())->toBe(2);
});

it('resolves to nobody when the last recipient is deactivated', function () {
    $scenario = RolloutFixture::emission(1);
    $only = RolloutFixture::operationalUser();

    app(SalesBoardRolloutRecipientDirectory::class)
        ->add($scenario['emission'], SalesBoardRolloutRecipientRole::Operational, $only, null);

    $only->forceFill(['approved_at' => null])->save();

    $target = SalesBoardAutomationTarget::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
    ]);

    // Vazio é resultado legítimo: o motor registra o aviso estruturado e a
    // execução segue. Desligar a automação por falta de destinatário seria
    // trocar um problema de aviso por um de apuração.
    expect(app(SalesBoardAutomationRecipientResolver::class)->forGenerationBlocked($target))->toBe([]);
});

it('refuses to configure a recipient that is not operational', function () {
    $scenario = RolloutFixture::emission(1);
    $pending = User::factory()->create(['approved_at' => null]);

    expect(fn () => app(SalesBoardRolloutRecipientDirectory::class)
        ->add($scenario['emission'], SalesBoardRolloutRecipientRole::Operational, $pending, null))
        ->toThrow(SalesBoardRolloutException::class, 'ativos e aprovados');
});

it('never configures the same person twice in the same role', function () {
    $scenario = RolloutFixture::emission(1);
    $user = RolloutFixture::operationalUser();
    $directory = app(SalesBoardRolloutRecipientDirectory::class);

    $directory->add($scenario['emission'], SalesBoardRolloutRecipientRole::Operational, $user, null);
    $directory->add($scenario['emission'], SalesBoardRolloutRecipientRole::Operational, $user, null);

    expect(SalesBoardRolloutRecipient::query()->count())->toBe(1);
});

it('lets the same person cover both roles', function () {
    $scenario = RolloutFixture::emission(1);
    $user = RolloutFixture::operationalUser();
    $directory = app(SalesBoardRolloutRecipientDirectory::class);

    $directory->add($scenario['emission'], SalesBoardRolloutRecipientRole::Operational, $user, null);
    $directory->add($scenario['emission'], SalesBoardRolloutRecipientRole::Management, $user, null);

    expect(SalesBoardRolloutRecipient::query()->count())->toBe(2);
});

it('never grants any permission by being a recipient', function () {
    $scenario = RolloutFixture::emission(1);
    $user = RolloutFixture::operationalUser();

    app(SalesBoardRolloutRecipientDirectory::class)
        ->add($scenario['emission'], SalesBoardRolloutRecipientRole::Operational, $user, null);

    expect($user->fresh()->can('sales-boards.view'))->toBeFalse()
        ->and($user->fresh()->can('sales-boards.update'))->toBeFalse();
});

it('delivers a blocked-generation alert to the operational recipient only', function () {
    $scenario = RolloutFixture::emission(1);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    // Uma unidade sem valor: a apuração bloqueia e o alerta dispara.
    ConstructionUnit::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
        'block' => '01', 'unit' => '950',
        'base_value' => null, 'base_value_reference_date' => null,
    ]);

    $people = RolloutFixture::recipients($scenario['emission']);
    RolloutFixture::reviewImpacts(
        $homologation = RolloutFixture::open($scenario['emission'])
    );

    // O bloqueio impede a homologação; a ativação é forçada pelo caminho de
    // dados para exercitar só o roteamento do alerta.
    $scenario['emission']->forceFill([
        'sales_board_source' => SalesBoardSource::Automated,
        'sales_board_automation_start_reference_month' => '2026-08-01',
        'sales_board_active_homologation_id' => $homologation->id,
    ])->save();

    RolloutFixture::enableGlobalAutomation();
    config()->set('sales_board.automation.reminders.blocked_after_days', 0);

    app(SalesBoardAutomationService::class)->run(asOf: CarbonImmutable::parse('2026-09-13'));

    $alert = SalesBoardAutomationAlert::query()->sole();

    expect($alert->alert_type)->toBe(SalesBoardAutomationAlertType::GenerationBlocked)
        ->and($alert->recipient_user_id)->toBe($people['operational']->id);

    Notification::assertSentTo($people['operational'], SalesBoardAutomationNotification::class);
    Notification::assertNotSentTo($people['management'], SalesBoardAutomationNotification::class);
});
