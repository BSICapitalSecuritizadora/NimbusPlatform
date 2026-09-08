<?php

use App\Enums\SalesBoardRolloutComparisonStatus;
use App\Enums\SalesBoardRolloutEventType;
use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Enums\SalesBoardSource;
use App\Models\Emission;
use App\Models\SalesBoardRolloutEvent;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Models\SalesBoardRolloutRecipient;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * As garantias do rollout que dependem de o banco ser um banco específico.
 */
pest()->group('parity');

/**
 * O bug do hardening não se repete: no SQLite um valor longo demais passa, e no
 * MySQL ele é truncado em silêncio.
 */
it('keeps every persisted enum value within its column width', function () {
    $limits = [
        [SalesBoardSource::cases(), 20, 'sales board source'],
        [SalesBoardRolloutHomologationStatus::cases(), 30, 'homologation status'],
        [SalesBoardRolloutComparisonStatus::cases(), 30, 'comparison status'],
        [SalesBoardRolloutRecipientRole::cases(), 20, 'recipient role'],
        [SalesBoardRolloutEventType::cases(), 30, 'event type'],
    ];

    foreach ($limits as [$cases, $max, $label]) {
        foreach ($cases as $case) {
            expect(strlen($case->value))->toBeLessThanOrEqual($max, $label.' '.$case->name);
        }
    }
});

it('persists every rollout enum without truncation', function () {
    $scenario = RolloutFixture::emission(1);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);

    $emission = DB::table('emissions')->where('id', $scenario['emission']->id)->sole();
    $persisted = DB::table('sales_board_rollout_homologations')->where('id', $homologation->id)->sole();
    $event = DB::table('sales_board_rollout_events')->sole();
    $recipient = DB::table('sales_board_rollout_recipients')->first();

    expect($emission->sales_board_source)->toBe(SalesBoardSource::Automated->value)
        ->and($persisted->status)->toBe(SalesBoardRolloutHomologationStatus::Approved->value)
        ->and($event->event_type)->toBe(SalesBoardRolloutEventType::Activated->value)
        ->and($event->from_source)->toBe(SalesBoardSource::Legacy->value)
        ->and($event->to_source)->toBe(SalesBoardSource::Automated->value)
        ->and($recipient->role)->toBeIn([
            SalesBoardRolloutRecipientRole::Operational->value,
            SalesBoardRolloutRecipientRole::Management->value,
        ])
        // Reler pelo model prova que o gravado ainda resolve o enum.
        ->and(Emission::query()->find($scenario['emission']->id)->sales_board_source)
        ->toBe(SalesBoardSource::Automated);
});

it('defaults every existing emission to legacy at the database level', function () {
    // Uma linha escrita **sem** as colunas de rollout, como toda Emissão que já
    // existia antes desta fase: o default da coluna é a única coisa que decide
    // o modo delas, e é ele que impede um deploy de automatizar a carteira.
    $attributes = collect(Emission::factory()->raw())
        ->except([
            'sales_board_source',
            'sales_board_automation_start_reference_month',
            'sales_board_auto_open_builder_review',
            'sales_board_active_homologation_id',
        ])
        ->put('created_at', now())
        ->put('updated_at', now())
        ->all();

    $id = DB::table('emissions')->insertGetId($attributes);

    $row = DB::table('emissions')->where('id', $id)->sole();

    expect($row->sales_board_source)->toBe(SalesBoardSource::Legacy->value)
        ->and($row->sales_board_automation_start_reference_month)->toBeNull()
        ->and((int) $row->sales_board_auto_open_builder_review)->toBe(0)
        ->and($row->sales_board_active_homologation_id)->toBeNull();
});

it('allows a single homologation attempt per emission', function () {
    $scenario = RolloutFixture::emission(1);
    $homologation = RolloutFixture::open($scenario['emission']);

    SalesBoardRolloutHomologation::factory()->create([
        'emission_id' => $homologation->emission_id,
        'attempt' => $homologation->attempt,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows a single row per construction inside a homologation', function () {
    $scenario = RolloutFixture::emission(1);
    $homologation = RolloutFixture::open($scenario['emission']);

    SalesBoardRolloutHomologationConstruction::factory()->create([
        'sales_board_rollout_homologation_id' => $homologation->id,
        'construction_id' => $scenario['constructions'][0]->id,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows a single recipient per emission, role and user', function () {
    $scenario = RolloutFixture::emission(1);
    $user = RolloutFixture::operationalUser();

    SalesBoardRolloutRecipient::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'role' => SalesBoardRolloutRecipientRole::Operational,
        'user_id' => $user->id,
    ]);

    SalesBoardRolloutRecipient::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'role' => SalesBoardRolloutRecipientRole::Operational,
        'user_id' => $user->id,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('refuses to delete an emission that has a homologation', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::open($scenario['emission']);

    DB::table('emissions')->where('id', $scenario['emission']->id)->delete();
})->throws(QueryException::class);

it('refuses to delete a construction that a homologation assessed', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::open($scenario['emission']);

    DB::table('constructions')->where('id', $scenario['constructions'][0]->id)->delete();
})->throws(QueryException::class);

it('refuses to delete a user configured as a recipient', function () {
    $scenario = RolloutFixture::emission(1);
    $user = RolloutFixture::operationalUser();

    SalesBoardRolloutRecipient::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'role' => SalesBoardRolloutRecipientRole::Management,
        'user_id' => $user->id,
    ]);

    // Apagar a conta não pode esvaziar em silêncio a configuração de avisos de
    // uma Emissão automatizada.
    DB::table('users')->where('id', $user->id)->delete();
})->throws(QueryException::class);

it('refuses to delete a homologation referenced by an event', function () {
    $scenario = RolloutFixture::emission(1);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);

    // O ponteiro da Emissão precisa sair antes; a trilha é que segura.
    DB::table('emissions')->where('id', $scenario['emission']->id)
        ->update(['sales_board_active_homologation_id' => null]);

    DB::table('sales_board_rollout_homologations')->where('id', $homologation->id)->delete();
})->throws(QueryException::class);

it('round-trips the dates and the canonical json positions', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);

    $homologation = RolloutFixture::open($scenario['emission']);
    $row = $homologation->constructions->sole();

    $persisted = DB::table('sales_board_rollout_homologation_constructions')
        ->where('id', $row->id)
        ->sole();

    expect(substr((string) DB::table('sales_board_rollout_homologations')
        ->where('id', $homologation->id)->value('proposed_start_reference_month'), 0, 10))
        ->toBe('2026-08-01')
        ->and($row->legacy_reference_month->format('Y-m-d'))->toBe('2026-07-01')
        // O JSON volta como estrutura, não como texto.
        ->and(json_decode((string) $persisted->derived_position, true)['buckets']['stock']['units'])->toBe(2)
        ->and($row->derived_position['version'])->toBe(1)
        ->and($row->legacy_position['buckets']['stock']['value_cents'])->toBe(100_000_000);
});

it('never lets a rollout event be rewritten or deleted', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);

    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);

    $event = SalesBoardRolloutEvent::query()->sole();

    expect(fn () => $event->forceFill(['reason' => 'reescrevendo'])->save())
        ->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $event->delete())->toThrow(LogicException::class, 'append-only');
});
