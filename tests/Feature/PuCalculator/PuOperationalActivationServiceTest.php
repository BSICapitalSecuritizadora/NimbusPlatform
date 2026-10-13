<?php

use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Enums\PuOperationalReadinessStatus;
use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;
use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;
use App\Domain\PuCalculator\Services\PuOperationalActivationService;
use App\Domain\PuCalculator\Support\PuOperationsConfig;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuOperationalStatusChange;
use App\Models\IndexRate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Support\Pu\PuObligationFixture as Fx;
use Tests\Support\Pu\PuOperationalActivationFixture as Activation;

/**
 * Fase 7 -- ativar a operação do PU é um ato próprio: autorizado
 * (`pu.operations.activate`, que nenhuma outra autoridade do PU concede), com
 * motivo, referência da aprovação documentada e avaliação de prontidão sem
 * bloqueio, gravado num livro só de inclusão e na trilha protegida. Homologar não
 * ativa, ativar não homologa, abrir a tela não ativa, e nenhuma migration ativa.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Activation::withoutAutomaticActivation();
});

function p7aActivator(array $permissions = [AccessPermission::PuOperationsActivate]): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_map(fn (AccessPermission $permission): string => $permission->value, $permissions));

    return $user->fresh();
}

function p7aEmission(): Emission
{
    return Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
}

function p7aActivate(Emission $emission, User $actor, string $reason = 'Revisão contratual concluída.', string $approval = 'ATA-2026-10-13'): EmissionPuOperationalStatusChange
{
    return app(PuOperationalActivationService::class)->activate($emission, $actor, $reason, $approval);
}

it('activates an emission with an authorized actor, recording every element of the decision and the protected audit entry', function () {
    $emission = p7aEmission();
    $actor = p7aActivator();

    $change = p7aActivate($emission, $actor);
    $activity = Activity::query()->where('event', 'operations_activated')->sole();

    expect($change->status)->toBe(PuOperationalActivationStatus::Activated)
        ->and($change->previous_status)->toBe(PuOperationalActivationStatus::Inactive)
        ->and($change->decided_by)->toBe($actor->id)
        ->and($change->reason)->toBe('Revisão contratual concluída.')
        ->and($change->approval_reference)->toBe('ATA-2026-10-13')
        ->and($change->readiness_status)->toBeIn([PuOperationalReadinessStatus::TechnicallyReady->value, PuOperationalReadinessStatus::ReadyForReview->value])
        ->and($change->readiness_snapshot['emission_id'])->toBe($emission->id)
        ->and($change->readiness_snapshot['contractual_checklist'])->not->toBeEmpty()
        ->and($change->decided_at)->not->toBeNull()
        ->and(app(PuOperationalActivationPolicy::class)->status($emission))->toBe(PuOperationalActivationStatus::Activated)
        ->and($activity->log_name)->toBe('pu-calculation')
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->subject_id)->toBe($emission->id)
        ->and($activity->properties['previous_status'])->toBe('inactive')
        ->and($activity->properties['new_status'])->toBe('activated')
        ->and($activity->properties['approval_reference'])->toBe('ATA-2026-10-13')
        ->and(config('audit.protected_events.pu-calculation'))->toContain('operations_activated', 'operations_suspended');
});

it('refuses activation to anyone without the dedicated authority, including holders of every other PU permission', function () {
    $emission = p7aEmission();
    $broad = p7aActivator([
        AccessPermission::PuParametersConfigure,
        AccessPermission::PuCurveGenerate,
        AccessPermission::PuCurveValidate,
        AccessPermission::PuCurveHomologate,
        AccessPermission::PuSettlementRecord,
        AccessPermission::PuOperationsMonitor,
        AccessPermission::PuOperationsRecover,
    ]);

    expect(fn () => p7aActivate($emission, $broad))->toThrow(AuthorizationException::class, 'pu.operations.activate')
        ->and(fn () => app(PuOperationalActivationService::class)->suspend($emission, $broad, 'Parar.'))->toThrow(AuthorizationException::class)
        ->and(EmissionPuOperationalStatusChange::query()->count())->toBe(0);
});

it('refuses activation by a deactivated or not yet approved user even with the authority', function () {
    $emission = p7aEmission();
    $inactive = p7aActivator();
    $inactive->forceFill(['is_active' => false])->save();
    $unapproved = p7aActivator();
    $unapproved->forceFill(['approved_at' => null])->save();

    expect(fn () => p7aActivate($emission, $inactive->fresh()))->toThrow(AuthorizationException::class)
        ->and(fn () => p7aActivate($emission, $unapproved->fresh()))->toThrow(AuthorizationException::class)
        ->and(EmissionPuOperationalStatusChange::query()->count())->toBe(0);
});

it('requires the reason and the documented approval reference', function () {
    $emission = p7aEmission();
    $actor = p7aActivator();

    expect(fn () => p7aActivate($emission, $actor, reason: '   '))->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_MISSING_JUSTIFICATION)
        ->and(fn () => p7aActivate($emission, $actor, approval: ''))->toThrow(PuOperationalActivationException::class, 'aprovação documentada')
        ->and(fn () => app(PuOperationalActivationService::class)->suspend($emission, $actor, ''))->toThrow(PuOperationalActivationException::class)
        ->and(EmissionPuOperationalStatusChange::query()->count())->toBe(0);
});

it('refuses to activate while PU operations are disabled in the environment, but always allows suspending', function () {
    $emission = p7aEmission();
    $actor = p7aActivator();
    Activation::activate($emission->id);
    $other = p7aEmission();
    config([PuOperationsConfig::ENABLED_KEY => false]);

    expect(fn () => p7aActivate($other, $actor))->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_GLOBALLY_DISABLED);

    $suspension = app(PuOperationalActivationService::class)->suspend($emission, $actor, 'Incidente em produção.');

    expect($suspension->status)->toBe(PuOperationalActivationStatus::Suspended)
        ->and($suspension->previous_status)->toBe(PuOperationalActivationStatus::Activated)
        ->and(app(PuOperationalActivationPolicy::class)->status($other))->toBe(PuOperationalActivationStatus::Inactive);
});

it('refuses to activate an emission whose readiness has a blocker, listing why', function () {
    $emission = p7aEmission();
    IndexRate::query()->whereDate('rate_date', '2026-03-04')->delete();
    $actor = p7aActivator();

    expect(fn () => p7aActivate($emission, $actor))->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_NOT_READY)
        ->and(EmissionPuOperationalStatusChange::query()->count())->toBe(0);
});

it('refuses to activate an emission without PU parameters', function () {
    $emission = Emission::factory()->active()->create();

    expect(fn () => p7aActivate($emission, p7aActivator()))->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_NOT_READY)
        ->and(app(PuOperationalActivationPolicy::class)->status($emission))->toBe(PuOperationalActivationStatus::NotConfigured);
});

it('records each transition once: a second activation and a suspension of an inactive emission are refused', function () {
    $emission = p7aEmission();
    $actor = p7aActivator();

    expect(fn () => app(PuOperationalActivationService::class)->suspend($emission, $actor, 'Parar.'))
        ->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_INVALID_TRANSITION);

    p7aActivate($emission, $actor);

    expect(fn () => p7aActivate($emission, $actor))->toThrow(PuOperationalActivationException::class, PuOperationalActivationException::REASON_INVALID_TRANSITION);

    app(PuOperationalActivationService::class)->suspend($emission, $actor, 'Revisão do Termo.');
    $reactivation = p7aActivate($emission, $actor, 'Termo revisado.', 'ATA-2026-10-20');

    expect(EmissionPuOperationalStatusChange::query()->where('emission_id', $emission->id)->orderBy('id')->pluck('status')->map->value->all())
        ->toBe(['activated', 'suspended', 'activated'])
        ->and($reactivation->previous_status)->toBe(PuOperationalActivationStatus::Suspended);
});

it('keeps every activation decision immutable and undeletable', function () {
    $change = p7aActivate(p7aEmission(), p7aActivator());

    expect(fn () => $change->forceFill(['reason' => 'outro'])->save())->toThrow(LogicException::class)
        ->and(fn () => $change->delete())->toThrow(LogicException::class)
        ->and(fn () => EmissionPuOperationalStatusChange::query()->create([
            'emission_id' => $change->emission_id,
            'status' => PuOperationalActivationStatus::Inactive,
            'previous_status' => PuOperationalActivationStatus::Activated,
            'reason' => 'x',
            'decided_via' => 'console',
            'decided_at' => now(),
        ]))->toThrow(LogicException::class);
});

it('activates and suspends from the console only for an authorized user', function () {
    $emission = p7aEmission();
    $outsider = p7aActivator([AccessPermission::PuCurveHomologate]);
    $actor = p7aActivator();

    $this->artisan('pu:operations:activation', ['action' => 'activate', 'emission' => $emission->id, '--user' => $outsider->email, '--reason' => 'x', '--approval-reference' => 'y'])
        ->expectsOutputToContain('pu.operations.activate')
        ->assertFailed();

    $this->artisan('pu:operations:activation', ['action' => 'activate', 'emission' => $emission->id, '--user' => $actor->email, '--reason' => 'Revisão concluída.', '--approval-reference' => 'ATA-1'])
        ->assertSuccessful();

    $this->artisan('pu:operations:activation', ['action' => 'suspend', 'emission' => $emission->id, '--user' => (string) $actor->id, '--reason' => 'Parar.'])
        ->assertSuccessful();

    expect(EmissionPuOperationalStatusChange::query()->where('emission_id', $emission->id)->count())->toBe(2)
        ->and(app(PuOperationalActivationPolicy::class)->status($emission))->toBe(PuOperationalActivationStatus::Suspended);
});

it('never activates an existing configured emission when the activation migration runs on a populated database', function () {
    $emission = p7aEmission();
    Schema::drop('emission_pu_operational_status_changes');

    (require database_path('migrations/2026_10_13_150000_create_emission_pu_operational_status_changes_table.php'))->up();

    expect(EmissionPuOperationalStatusChange::query()->count())->toBe(0)
        ->and(app(PuOperationalActivationPolicy::class)->status($emission))->toBe(PuOperationalActivationStatus::Inactive);
});

it('grants the activation authority only to super-admin and admin roles', function () {
    $roles = Role::query()
        ->whereHas('permissions', fn ($query) => $query->where('name', AccessPermission::PuOperationsActivate->value))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($roles)->toBe(['admin', 'super-admin'])
        ->and(AccessPermission::PuOperationsActivate->isCritical())->toBeTrue();
});
