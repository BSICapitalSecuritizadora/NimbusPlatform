<?php

use App\Enums\ContractStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\ConstructionUnitRetirementException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitRetirement;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\ConstructionUnitRetirementService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\GovernanceFixture;

/**
 * Baixa e reativação de unidade: decisão da Gestão, com motivo, autor, data de
 * negócio que não alcança competência publicada e a unidade livre de contrato e
 * de permuta dali em diante.
 *
 * Roda também no MySQL: a unique sobre a coluna gerada é a trava de uma baixa
 * aberta por unidade, e ela precisa valer nos dois bancos.
 */
uses(RefreshDatabase::class);

pest()->group('parity');

const RETIREMENT_REASON = 'Unidade cadastrada em duplicidade na carga inicial.';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
});

function retirementUnit(string $number = '101'): ConstructionUnit
{
    $construction = Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
    ]);

    return DerivationFixture::unit($construction, $number);
}

function retireUnit(
    ConstructionUnit $unit,
    string $date = '2026-08-10',
    ?User $actor = null,
    string $reason = RETIREMENT_REASON,
): ConstructionUnitRetirement {
    return app(ConstructionUnitRetirementService::class)->retire(
        $unit,
        $actor ?? GovernanceFixture::approver(),
        CarbonImmutable::parse($date),
        $reason,
    );
}

function reactivateRetirement(
    ConstructionUnitRetirement $retirement,
    string $date,
    ?User $actor = null,
    string $reason = 'Baixa registrada por engano: a unidade existe.',
): ConstructionUnitRetirement {
    return app(ConstructionUnitRetirementService::class)->reactivate(
        $retirement,
        $actor ?? GovernanceFixture::approver(),
        CarbonImmutable::parse($date),
        $reason,
    );
}

/**
 * Uma competência publicada da obra: o ciclo e a publicação dele. É a
 * publicação que conta, não o status.
 */
function publishCompetence(Construction $construction, string $month, SalesBoardCycleStatus $status = SalesBoardCycleStatus::Approved): SalesBoardCycle
{
    $cycle = SalesBoardCycle::factory()->forConstruction($construction)->referenceMonth($month)->create(['status' => $status]);

    SalesBoardPublication::factory()->create(['sales_board_cycle_id' => $cycle->id]);

    return $cycle;
}

/**
 * O contrato que cada caso deixa na unidade, por nome e não por closure no
 * dataset: a lista mostra lado a lado o que ocupa e o que não ocupa a unidade
 * a partir de 10/08/2026.
 */
function retirementContractCase(string $case, ConstructionUnit $unit): void
{
    match ($case) {
        'venda ativa' => DerivationFixture::contract($unit, '2026-05-10'),
        'distrato depois da data' => DerivationFixture::contract($unit, '2026-05-10', cancellationDate: '2026-08-20', status: ContractStatus::Cancelled),
        'distratado sem data' => DerivationFixture::contract($unit, '2026-05-10', status: ContractStatus::Cancelled),
        'venda posterior à data' => DerivationFixture::contract($unit, '2026-08-15'),
        'distrato na própria data' => DerivationFixture::contract($unit, '2026-05-10', cancellationDate: '2026-08-10', status: ContractStatus::Cancelled),
        'contrato excluído' => DerivationFixture::contract($unit, '2026-05-10')->delete(),
    };
}

it('records the retirement with author, reason and business date under the protected log', function () {
    $unit = retirementUnit();
    $approver = GovernanceFixture::approver();
    $this->actingAs($approver);

    $retirement = retireUnit($unit, '2026-08-10', $approver, '  <b>Unidade cadastrada em duplicidade</b> na carga inicial.  ');

    expect($retirement->retired_on->toDateString())->toBe('2026-08-10')
        ->and($retirement->reason)->toBe('Unidade cadastrada em duplicidade na carga inicial.')
        ->and($retirement->retired_by_id)->toBe($approver->id)
        ->and($retirement->isOpen())->toBeTrue();

    $trail = Activity::query()
        ->where('subject_type', ConstructionUnitRetirement::class)
        ->where('subject_id', $retirement->id)
        ->get();

    expect($trail->pluck('log_name')->unique()->values()->all())->toBe(['construction_unit_retirements'])
        ->and($trail->sole()->event)->toBe('created')
        ->and($trail->sole()->attribute_changes['attributes']['retired_on'])->toStartWith('2026-08-10')
        ->and($trail->sole()->attribute_changes['attributes']['reason'])->toBe('Unidade cadastrada em duplicidade na carga inicial.')
        ->and((int) $trail->sole()->causer_id)->toBe($approver->id)
        ->and(Activity::query()->where('subject_type', ConstructionUnitRetirement::class)->where('log_name', 'default')->exists())->toBeFalse();
});

it('requires the management authority, whatever the emission status', function (string $actor, bool $allowed) {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $unit = retirementUnit();

    $user = match ($actor) {
        'editor' => tap(User::factory()->create())->assignRole('editor'),
        'approver' => GovernanceFixture::approver(),
        'super-admin' => GovernanceFixture::superAdmin(),
    };

    if (! $allowed) {
        expect(fn () => retireUnit($unit, actor: $user))->toThrow(AuthorizationException::class);

        expect(ConstructionUnitRetirement::query()->count())->toBe(0);

        return;
    }

    expect(retireUnit($unit, actor: $user)->retired_by_id)->toBe($user->id);
})->with([
    'editor do cadastro' => ['editor', false],
    'Gestão' => ['approver', true],
    'super-admin' => ['super-admin', true],
]);

it('requires an identified actor and records nothing without one', function () {
    $unit = retirementUnit();

    expect(fn () => app(ConstructionUnitRetirementService::class)->retire($unit, null, CarbonImmutable::parse('2026-08-10'), RETIREMENT_REASON))
        ->toThrow(ConstructionUnitRetirementException::class, 'exige um usuário identificado');

    expect(ConstructionUnitRetirement::query()->count())->toBe(0);
});

it('refuses a reason shorter than ten characters once trimmed and stripped of tags', function () {
    $unit = retirementUnit();

    expect(fn () => retireUnit($unit, reason: '  <p>Engano</p>   '))
        ->toThrow(ConstructionUnitRetirementException::class, 'pelo menos 10 caracteres');

    expect(ConstructionUnitRetirement::query()->count())->toBe(0);
});

it('uses the business day, not the UTC day, to refuse a future date', function () {
    $unit = retirementUnit();

    // 01:30 em UTC ainda é 30/09 em São Paulo.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 01:30:00', 'UTC'));

    expect(fn () => retireUnit($unit, '2026-10-01'))
        ->toThrow(ConstructionUnitRetirementException::class, 'não pode ser posterior a hoje (30/09/2026)');

    expect(retireUnit($unit, '2026-09-30')->retired_on->toDateString())->toBe('2026-09-30');
});

it('never reaches a published competence', function () {
    $unit = retirementUnit();
    publishCompetence($unit->construction, '2026-07-01');

    expect(fn () => retireUnit($unit, '2026-07-31'))
        ->toThrow(ConstructionUnitRetirementException::class, 'posterior a 31/07/2026');

    expect(ConstructionUnitRetirement::query()->count())->toBe(0)
        ->and(retireUnit($unit, '2026-08-01')->retired_on->toDateString())->toBe('2026-08-01');
});

it('reads the published competence from the publication, not from the approved status', function () {
    $unit = retirementUnit();

    // Aprovado sem publicação não protege nada; publicado em retificação
    // (de volta a Gerado) continua protegido.
    SalesBoardCycle::factory()->forConstruction($unit->construction)->referenceMonth('2026-08-01')->create(['status' => SalesBoardCycleStatus::Approved]);
    publishCompetence($unit->construction, '2026-07-01', SalesBoardCycleStatus::Generated);

    expect(fn () => retireUnit($unit, '2026-07-20'))
        ->toThrow(ConstructionUnitRetirementException::class, 'posterior a 31/07/2026');

    expect(retireUnit($unit, '2026-08-05')->retired_on->toDateString())->toBe('2026-08-05')
        ->and(app(ConstructionUnitRetirementService::class)->earliestEffectiveDate($unit)?->toDateString())->toBe('2026-08-01');
});

it('refuses while a contract holds the unit on the date or later', function (string $case, ?string $message) {
    $unit = retirementUnit();
    retirementContractCase($case, $unit);

    if ($message === null) {
        expect(retireUnit($unit, '2026-08-10')->retired_on->toDateString())->toBe('2026-08-10');

        return;
    }

    expect(fn () => retireUnit($unit, '2026-08-10'))
        ->toThrow(ConstructionUnitRetirementException::class, $message);

    expect(ConstructionUnitRetirement::query()->count())->toBe(0);
})->with([
    'venda ativa' => ['venda ativa', 'a baixa só pode valer a partir do distrato. Registre o distrato'],
    'distrato depois da data' => ['distrato depois da data', 'ocupa a unidade até o distrato em 20/08/2026'],
    'distratado sem data' => ['distratado sem data', 'está distratado sem data de distrato'],
    'venda posterior à data' => ['venda posterior à data', 'a baixa só pode valer a partir do distrato'],
    'distrato na própria data' => ['distrato na própria data', null],
    'contrato excluído' => ['contrato excluído', null],
]);

it('refuses while an exchange is in force on the date or later, and accepts once it ended by then', function () {
    $unit = retirementUnit();
    $exchange = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->endedOn('2026-08-11')->create();

    expect(fn () => retireUnit($unit, '2026-08-10'))
        ->toThrow(ConstructionUnitRetirementException::class, 'permuta vigente a partir de 01/01/2026 até o encerramento em 11/08/2026');

    DB::table('construction_unit_exchanges')->where('id', $exchange->id)->update(['ended_on' => '2026-08-10']);

    expect(retireUnit($unit, '2026-08-10')->retired_on->toDateString())->toBe('2026-08-10');
});

it('refuses an exchange that only starts after the date', function () {
    $unit = retirementUnit();
    ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-09-01')->create();

    expect(fn () => retireUnit($unit, '2026-08-10'))
        ->toThrow(ConstructionUnitRetirementException::class, 'permuta vigente a partir de 01/09/2026');
});

it('keeps a single open retirement per unit, in the service and in the database', function () {
    $unit = retirementUnit();
    retireUnit($unit, '2026-08-10');

    expect(fn () => retireUnit($unit, '2026-08-15'))
        ->toThrow(ConstructionUnitRetirementException::class, 'já está baixada desde 10/08/2026');

    expect(fn () => DB::table('construction_unit_retirements')->insert([
        'construction_unit_id' => $unit->id,
        'retired_on' => '2026-09-01',
        'reason' => RETIREMENT_REASON,
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(ConstructionUnitRetirement::query()->where('construction_unit_id', $unit->id)->count())->toBe(1);
});

it('refuses to overlap a closed retirement and ignores an annulled one', function () {
    $unit = retirementUnit();
    ConstructionUnitRetirement::factory()->forUnit($unit)->retiredOn('2026-08-10')->reactivatedOn('2026-08-20')->create();

    expect(fn () => retireUnit($unit, '2026-08-15'))
        ->toThrow(ConstructionUnitRetirementException::class, 'esteve baixada de 10/08/2026 a 19/08/2026: a nova baixa precisa começar em 20/08/2026 ou depois');

    expect(retireUnit($unit, '2026-08-20')->retired_on->toDateString())->toBe('2026-08-20');

    $other = retirementUnit('102');
    ConstructionUnitRetirement::factory()->forUnit($other)->retiredOn('2026-08-10')->reactivatedOn('2026-08-10')->create();

    expect(retireUnit($other, '2026-08-01')->retired_on->toDateString())->toBe('2026-08-01');
});

describe('reativação', function () {
    it('reactivates once, recording who, when and why', function () {
        $unit = retirementUnit();
        $retirement = retireUnit($unit, '2026-08-10');
        $approver = GovernanceFixture::approver();

        $reactivated = reactivateRetirement($retirement, '2026-09-01', $approver);

        expect($reactivated->reactivated_on->toDateString())->toBe('2026-09-01')
            ->and($reactivated->reactivated_by_id)->toBe($approver->id)
            ->and($reactivated->reactivated_at)->not->toBeNull()
            ->and($reactivated->reactivation_reason)->toBe('Baixa registrada por engano: a unidade existe.')
            ->and($reactivated->isOpen())->toBeFalse()
            ->and($reactivated->isAnnulled())->toBeFalse();

        expect(fn () => reactivateRetirement($retirement, '2026-09-10'))
            ->toThrow(ConstructionUnitRetirementException::class, 'já foi encerrada em 01/09/2026');

        $updated = Activity::query()
            ->where('subject_type', ConstructionUnitRetirement::class)
            ->where('subject_id', $retirement->id)
            ->where('event', 'updated')
            ->sole();

        expect($updated->log_name)->toBe('construction_unit_retirements')
            ->and($updated->attribute_changes['attributes']['reactivated_on'])->toStartWith('2026-09-01');
    });

    it('requires the management authority and a reason', function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);

        $retirement = retireUnit(retirementUnit(), '2026-08-10');
        $editor = tap(User::factory()->create())->assignRole('editor');

        expect(fn () => reactivateRetirement($retirement, '2026-09-01', $editor))->toThrow(AuthorizationException::class)
            ->and(fn () => reactivateRetirement($retirement, '2026-09-01', reason: 'curto'))->toThrow(ConstructionUnitRetirementException::class, 'pelo menos 10 caracteres');

        expect($retirement->fresh()->isOpen())->toBeTrue();
    });

    it('refuses a reactivation before the retirement, in the future or inside a published competence', function () {
        $unit = retirementUnit();
        $retirement = retireUnit($unit, '2026-08-10');

        expect(fn () => reactivateRetirement($retirement, '2026-08-09'))
            ->toThrow(ConstructionUnitRetirementException::class, 'não pode ser anterior à baixa (10/08/2026)')
            ->and(fn () => reactivateRetirement($retirement, '2026-09-21'))
            ->toThrow(ConstructionUnitRetirementException::class, 'não pode ser posterior a hoje (20/09/2026)');

        publishCompetence($unit->construction, '2026-08-01');

        expect(fn () => reactivateRetirement($retirement, '2026-08-31'))
            ->toThrow(ConstructionUnitRetirementException::class, 'posterior a 31/08/2026');

        expect($retirement->fresh()->isOpen())->toBeTrue()
            ->and(reactivateRetirement($retirement, '2026-09-01')->reactivated_on->toDateString())->toBe('2026-09-01');
    });

    it('annuls the retirement when reactivated on its own date, and accepts a new one from there', function () {
        $unit = retirementUnit();
        $retirement = retireUnit($unit, '2026-08-10');

        $annulled = reactivateRetirement($retirement, '2026-08-10');

        expect($annulled->isAnnulled())->toBeTrue()
            ->and($annulled->isEffectiveOn(CarbonImmutable::parse('2026-08-10')))->toBeFalse()
            ->and(retireUnit($unit, '2026-08-10')->isOpen())->toBeTrue();
    });
});

it('is append-only: nothing but the reactivation, once, is ever written again', function () {
    $retirement = ConstructionUnitRetirement::factory()->forUnit(retirementUnit())->retiredOn('2026-08-10')->create();

    expect(fn () => $retirement->fresh()->update(['retired_on' => '2026-08-11']))
        ->toThrow(LogicException::class, 'não é editada')
        ->and(fn () => $retirement->fresh()->update(['reason' => 'Outro motivo qualquer, posterior.']))
        ->toThrow(LogicException::class, 'não é editada')
        ->and(fn () => $retirement->fresh()->delete())
        ->toThrow(LogicException::class, 'não são excluídas');

    $retirement->fresh()->forceFill(['reactivated_on' => '2026-09-01', 'reactivation_reason' => 'Reativada pela Gestão.'])->save();

    expect(fn () => $retirement->fresh()->forceFill(['reactivated_on' => '2026-09-05'])->save())
        ->toThrow(LogicException::class, 'gravada uma única vez');

    expect($retirement->fresh()->retired_on->toDateString())->toBe('2026-08-10')
        ->and($retirement->fresh()->reactivated_on->toDateString())->toBe('2026-09-01');
});

it('lists only the open competences from the month of the date on', function () {
    $unit = retirementUnit();
    $construction = $unit->construction;

    $cycle = fn (string $month, SalesBoardCycleStatus $status): SalesBoardCycle => SalesBoardCycle::factory()
        ->forConstruction($construction)
        ->referenceMonth($month)
        ->create(['status' => $status]);

    $cycle('2026-06-01', SalesBoardCycleStatus::Generated);
    $cycle('2026-07-01', SalesBoardCycleStatus::Approved);
    $august = $cycle('2026-08-01', SalesBoardCycleStatus::Generated);
    $september = $cycle('2026-09-01', SalesBoardCycleStatus::BuilderReview);
    $cycle('2026-10-01', SalesBoardCycleStatus::Cancelled);

    $reached = app(ConstructionUnitRetirementService::class)->openCompetencesReachedBy($unit, CarbonImmutable::parse('2026-08-10'));

    expect($reached->pluck('id')->all())->toBe([$august->id, $september->id]);
});
