<?php

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Exceptions\SalesBoardRolloutException;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Models\Construction;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * As atestações de impacto valem para o retrato que foi visto.
 *
 * É a regra do aceite de diferença aplicada às duas atestações da Gestão: o
 * modal diz que ela "revisou os deltas apresentados", e uma reavaliação que
 * produz outro retrato apresenta outros deltas. Retrato igual não obriga
 * ninguém a atestar de novo -- o hash não tem relógio.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Um rascunho com os dois impactos atestados, os responsáveis definidos e o
 * empreendimento coincidente com o legado.
 *
 * @return array{homologation: SalesBoardRolloutHomologation, operator: User, approver: User, construction: Construction}
 */
function attestedDraft(): array
{
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);

    $operator = GovernanceFixture::operator();
    $approver = GovernanceFixture::approver();

    $homologation = RolloutFixture::open($scenario['emission'], $operator);
    RolloutFixture::recipients($scenario['emission'], $operator);
    RolloutFixture::reviewImpacts($homologation, $approver);

    return [
        'homologation' => $homologation->fresh(),
        'operator' => $operator,
        'approver' => $approver,
        'construction' => $scenario['constructions'][0],
    ];
}

/**
 * @return array{guarantees_reviewed_at: mixed, guarantees_reviewed_by_user_id: mixed, monthly_report_reviewed_at: mixed, monthly_report_reviewed_by_user_id: mixed}
 */
function attestationColumns(SalesBoardRolloutHomologation $homologation): array
{
    $fresh = $homologation->fresh();

    return [
        'guarantees_reviewed_at' => $fresh->guarantees_reviewed_at?->toIso8601String(),
        'guarantees_reviewed_by_user_id' => $fresh->guarantees_reviewed_by_user_id,
        'monthly_report_reviewed_at' => $fresh->monthly_report_reviewed_at?->toIso8601String(),
        'monthly_report_reviewed_by_user_id' => $fresh->monthly_report_reviewed_by_user_id,
    ];
}

/**
 * @return list<string> os rótulos dos itens do portão que não passaram
 */
function failedRolloutGateLabels(SalesBoardRolloutHomologation $homologation): array
{
    $gate = app(SalesBoardRolloutHomologationService::class)->gate($homologation->fresh(), $homologation->emission()->firstOrFail());

    return collect($gate['checks'])
        ->reject(fn (array $check): bool => $check['passed'])
        ->pluck('label')
        ->values()
        ->all();
}

it('resets both impact attestations when a reassessment changes the reviewed picture', function () {
    ['homologation' => $homologation, 'operator' => $operator, 'approver' => $approver, 'construction' => $construction] = attestedDraft();
    $service = app(SalesBoardRolloutHomologationService::class);

    expect($homologation->guaranteesReviewed())->toBeTrue()
        ->and($homologation->monthlyReportReviewed())->toBeTrue();

    // Uma unidade nova muda a posição apurada: o retrato atestado deixou de ser o da tela.
    DerivationFixture::unit($construction, 'A99');

    $reassessed = $service->reassess($homologation, $operator);

    expect($reassessed->assessment_hash)->not->toBe($homologation->assessment_hash)
        ->and(attestationColumns($reassessed))->toBe([
            'guarantees_reviewed_at' => null,
            'guarantees_reviewed_by_user_id' => null,
            'monthly_report_reviewed_at' => null,
            'monthly_report_reviewed_by_user_id' => null,
        ])
        ->and(failedRolloutGateLabels($reassessed))->toContain(
            'Impacto sobre as Garantias revisado',
            'Impacto sobre o Relatório Mensal revisado',
        );

    // A diferença nova é analisada; o que ainda impede a aprovação são as atestações.
    foreach ($reassessed->constructions as $row) {
        if ($row->requiresAcknowledgement()) {
            $service->acceptDifference($row, 'Unidade nova cadastrada depois da atestação.', $operator);
        }
    }

    expect(fn () => RolloutFixture::approve($reassessed, $approver))
        ->toThrow(SalesBoardRolloutException::class, 'Garantias ainda não foi revisado');

    expect($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Draft);
});

it('keeps both attestations when a reassessment finds the same picture', function () {
    ['homologation' => $homologation, 'operator' => $operator] = attestedDraft();
    $before = attestationColumns($homologation);
    $acceptances = $homologation->constructions->pluck('accepted_difference', 'id')->all();

    $this->travel(5)->minutes();

    $reassessed = app(SalesBoardRolloutHomologationService::class)->reassess($homologation, $operator);

    expect($reassessed->assessment_hash)->toBe($homologation->assessment_hash)
        ->and(attestationColumns($reassessed))->toBe($before)
        ->and($before['guarantees_reviewed_by_user_id'])->not->toBeNull()
        ->and($reassessed->constructions->pluck('accepted_difference', 'id')->all())->toBe($acceptances)
        ->and(failedRolloutGateLabels($reassessed))->toBe([]);
});

it('keeps the attestations when an approval is refused because the picture changed', function () {
    ['homologation' => $homologation, 'approver' => $approver, 'construction' => $construction] = attestedDraft();
    $before = attestationColumns($homologation);

    DerivationFixture::unit($construction, 'A96');

    // A aprovação reavalia, encontra outro retrato e recusa: a transação desfaz
    // também o zeramento. Só uma reavaliação de fato zera as atestações.
    expect(fn () => RolloutFixture::approve($homologation, $approver))
        ->toThrow(SalesBoardRolloutException::class, SalesBoardRolloutException::assessmentStale()->getMessage());

    expect(attestationColumns($homologation))->toBe($before)
        ->and($homologation->fresh()->assessment_hash)->toBe($homologation->assessment_hash)
        ->and($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Draft);
});

it('refuses an attestation from a screen that loaded the homologation before it was approved', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);

    $stale = RolloutFixture::open($scenario['emission'], GovernanceFixture::operator());
    RolloutFixture::recipients($scenario['emission']);

    $approver = GovernanceFixture::approver();
    RolloutFixture::reviewImpacts($stale, $approver);
    RolloutFixture::approve($stale, $approver);

    $approved = $stale->fresh();
    $attestedBefore = attestationColumns($approved);

    // A instância que a tela carregou ainda se acha rascunho; o banco já tem a aprovação.
    expect($stale->isEditable())->toBeTrue()
        ->and(fn () => app(SalesBoardRolloutHomologationService::class)->markGuaranteesReviewed($stale, GovernanceFixture::approver()))
        ->toThrow(SalesBoardRolloutException::class, 'já foi encerrada')
        ->and(fn () => app(SalesBoardRolloutHomologationService::class)->markMonthlyReportReviewed($stale, GovernanceFixture::approver()))
        ->toThrow(SalesBoardRolloutException::class, 'já foi encerrada');

    expect($approved->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and(attestationColumns($approved))->toBe($attestedBefore);
});

it('records who reset the attestation in the protected sales_board trail', function () {
    ['homologation' => $homologation, 'operator' => $operator, 'construction' => $construction] = attestedDraft();

    DerivationFixture::unit($construction, 'A98');

    $this->actingAs($operator);
    app(SalesBoardRolloutHomologationService::class)->reassess($homologation, $operator);

    $reset = Activity::query()
        ->where('subject_type', SalesBoardRolloutHomologation::class)
        ->where('subject_id', $homologation->id)
        ->orderBy('id')
        ->get()
        ->last(fn (Activity $activity): bool => data_get($activity->attribute_changes, 'old.guarantees_reviewed_by_user_id') !== null
            && array_key_exists('guarantees_reviewed_by_user_id', (array) data_get($activity->attribute_changes, 'attributes', []))
            && data_get($activity->attribute_changes, 'attributes.guarantees_reviewed_by_user_id') === null);

    expect($reset)->not->toBeNull()
        ->and($reset->log_name)->toBe('sales_board')
        ->and((int) $reset->causer_id)->toBe($operator->id)
        ->and(data_get($reset->attribute_changes, 'attributes.monthly_report_reviewed_by_user_id'))->toBeNull()
        ->and(data_get($reset->attribute_changes, 'old.monthly_report_reviewed_by_user_id'))->not->toBeNull();
});

it('tells the operator the attestations must be redone after a reassessment that changed the picture', function () {
    ['homologation' => $homologation, 'operator' => $operator, 'construction' => $construction] = attestedDraft();
    $this->actingAs($operator);

    // Retrato igual: nada a refazer.
    Livewire::test(ManageSalesBoardRollout::class, ['record' => $homologation->emission_id])
        ->callAction('reassess')
        ->assertHasNoActionErrors();

    expect(rolloutNotificationBody('Homologação reavaliada'))
        ->toBe('O retrato continua o mesmo: nada precisou ser refeito.');

    session()->forget(['filament.notifications', 'filament.claimed_notifications']);

    DerivationFixture::unit($construction, 'A97');

    $page = Livewire::test(ManageSalesBoardRollout::class, ['record' => $homologation->emission_id])
        ->callAction('reassess')
        ->assertHasNoActionErrors();

    expect(rolloutNotificationBody('Homologação reavaliada'))
        ->toBe('O retrato mudou: as diferenças cuja fonte mudou e as duas atestações de impacto voltaram a exigir análise.')
        ->and($homologation->fresh()->guaranteesReviewed())->toBeFalse();

    $page->assertNotified('Homologação reavaliada')
        ->assertSee('Ainda não revisado.');
});

/**
 * O corpo da notificação com o título dado, lido sem consumir a sessão:
 * `assertNotified()` a esvazia e compara só o título.
 */
function rolloutNotificationBody(string $title): ?string
{
    $notification = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->first(fn (array $notification): bool => ($notification['title'] ?? null) === $title);

    return $notification === null ? null : (string) ($notification['body'] ?? '');
}
