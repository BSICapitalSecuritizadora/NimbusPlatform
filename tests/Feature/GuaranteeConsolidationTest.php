<?php

use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeDocumentationStatus;
use App\Enums\GuaranteeDocumentReferenceType;
use App\Enums\GuaranteeEventType;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeMatchLevel;
use App\Enums\GuaranteeReconciliationOutcome;
use App\Enums\GuaranteeType;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Models\Bank;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedGuarantee;
use App\Models\Fund;
use App\Models\Guarantee;
use App\Models\User;
use App\Services\Guarantees\GuaranteeConsolidationPlanner;
use App\Services\Guarantees\GuaranteeFieldVersionWriter;
use App\Services\Guarantees\GuaranteeMatcher;
use App\Services\Guarantees\GuaranteeSuggestionReviewService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Cenário da tela: uma "Reserva de Obras" cadastrada sem origem documental, e
 * uma CCB que descreve a mesma reserva com banco, agência e conta.
 *
 * @return array{0: Emission, 1: Guarantee, 2: ExtractedGuarantee}
 */
function worksFundScenario(array $guaranteeAttributes = [], array $candidateAttributes = []): array
{
    $emission = Emission::factory()->create();

    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::WorksFund)
        ->create(array_merge([
            'emission_id' => $emission->id,
            'name' => 'Reserva de Obras',
            'legal_status' => GuaranteeLegalStatus::Active,
            'identification' => null,
            'contracted_value' => null,
        ], $guaranteeAttributes));

    $document = Document::factory()->create(['title' => 'Cédula de Crédito Bancário']);

    $candidate = ExtractedGuarantee::factory()
        ->worksFund()
        ->create(array_merge([
            'emission_id' => $emission->id,
            'document_id' => $document->id,
            'related_guarantee_id' => $guarantee->id,
            'reconciliation_outcome' => GuaranteeReconciliationOutcome::Complement,
            'match_score' => 0.85,
            'match_level' => GuaranteeMatchLevel::High,
            'match_evidence' => ['Mesma finalidade econômica: Obras'],
            'document_date' => '2026-05-14',
            'effective_date' => '2026-05-14',
        ], $candidateAttributes));

    return [$emission, $guarantee, $candidate];
}

it('recognises the same guarantee under a different name', function (): void {
    $emission = Emission::factory()->create();

    $existing = Guarantee::factory()
        ->ofType(GuaranteeType::WorksFund)
        ->create(['emission_id' => $emission->id, 'name' => 'Reserva de Obras']);

    $match = app(GuaranteeMatcher::class)->match(
        ['type' => GuaranteeType::WorksFund->value, 'name' => 'Fundo de Obras'],
        collect([$existing]),
    );

    expect($match)->not->toBeNull()
        ->and($match->guarantee->is($existing))->toBeTrue()
        ->and($match->level)->toBe(GuaranteeMatchLevel::High)
        ->and($match->evidence)->toContain('Mesma finalidade econômica: Obras');
});

/**
 * Nomes parecidos não bastam: um fundo de reserva e um fundo de obras são
 * patrimônios distintos, e consolidá-los apagaria uma garantia da operação.
 */
it('refuses to match funds with different economic purposes', function (): void {
    $emission = Emission::factory()->create();

    $reserveFund = Guarantee::factory()
        ->ofType(GuaranteeType::ReserveFund)
        ->create(['emission_id' => $emission->id, 'name' => 'Fundo de Reserva']);

    $match = app(GuaranteeMatcher::class)->match(
        ['type' => GuaranteeType::WorksFund->value, 'name' => 'Reserva de Obras'],
        collect([$reserveFund]),
    );

    expect($match)->toBeNull();
});

it('refuses to match when a strong identifier contradicts', function (): void {
    $emission = Emission::factory()->create();

    $existing = Guarantee::factory()
        ->ofType(GuaranteeType::RealEstateFiduciaryAlienation)
        ->create([
            'emission_id' => $emission->id,
            'name' => 'AF Imóvel',
            'identification' => ['registration_number' => '45.721'],
        ]);

    $match = app(GuaranteeMatcher::class)->match(
        [
            'type' => GuaranteeType::RealEstateFiduciaryAlienation->value,
            'name' => 'AF Imóvel',
            'identification' => ['registration_number' => '99.999'],
        ],
        collect([$existing]),
    );

    expect($match)->toBeNull();
});

it('plans the new information as complements, never as a conflict', function (): void {
    [, , $candidate] = worksFundScenario();

    $plan = app(GuaranteeConsolidationPlanner::class)->plan($candidate, $candidate->relatedGuarantee);

    expect($plan->outcome)->toBe(GuaranteeReconciliationOutcome::Complement)
        ->and($plan->hasDivergences())->toBeFalse()
        ->and($plan->providesFirstDocumentarySource)->toBeTrue();

    $labels = collect($plan->complements)->pluck('label')->all();

    expect($labels)->toContain('Banco')
        ->and($labels)->toContain('Agência')
        ->and($labels)->toContain('Conta');

    expect($plan->deltaFor('account')->currentDisplay)->toBe('Não informado')
        ->and($plan->deltaFor('account')->newDisplay)->toBe('185187-P');
});

it('complements the existing guarantee instead of creating a second one', function (): void {
    [, $guarantee, $candidate] = worksFundScenario();
    $actor = makeAdminUser();

    $complemented = app(GuaranteeSuggestionReviewService::class)->complement($candidate, $actor);

    expect($complemented->is($guarantee))->toBeTrue()
        ->and(Guarantee::query()->count())->toBe(1);

    $guarantee->refresh();

    expect($guarantee->identification['bank'])->toBe('Banco Bradesco S.A. (cód. 237)')
        ->and($guarantee->identification['agency'])->toBe('7748')
        ->and($guarantee->identification['account'])->toBe('185187-P');

    expect($candidate->refresh()->status)->toBe(GuaranteeDetectionStatus::Approved)
        ->and($candidate->guarantee_id)->toBe($guarantee->id);
});

/**
 * §4 e §5: a garantia responde de onde veio cada informação, e continua
 * podendo acumular fontes documentais de vários instrumentos.
 */
it('keeps the documentary origin of the complemented information', function (): void {
    [, $guarantee, $candidate] = worksFundScenario();

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    $reference = $guarantee->documentReferences()->sole();

    expect($reference->clause)->toBe('10.3 (a)')
        ->and($reference->page)->toBe(23)
        ->and($reference->document_label)->toBe('Cédula de Crédito Bancário')
        ->and($reference->reference_type)->toBe(GuaranteeDocumentReferenceType::Constitution);

    $event = $guarantee->events()->sole();

    expect($event->event_type)->toBe(GuaranteeEventType::Constitution)
        ->and($event->title)->toBe('Constituição comprovada documentalmente')
        ->and($event->guarantee_document_reference_id)->toBe($reference->id);
});

/**
 * §13: o cadastro manual sem fonte vira garantia comprovada documentalmente
 * sem que uma segunda garantia apareça.
 */
it('turns a manually registered guarantee into a documented one', function (): void {
    [, $guarantee, $candidate] = worksFundScenario();

    expect($guarantee->documentationStatus())->toBe(GuaranteeDocumentationStatus::DocumentationIdentified);

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    expect($guarantee->refresh()->load('documentReferences', 'pendingDetections')->documentationStatus())
        ->toBe(GuaranteeDocumentationStatus::DocumentedlyConfirmed);
});

it('reports a guarantee with no documentary source as manually registered', function (): void {
    $guarantee = Guarantee::factory()->ofType(GuaranteeType::WorksFund)->create();

    expect($guarantee->documentationStatus())->toBe(GuaranteeDocumentationStatus::ManuallyRegistered);
});

/**
 * §10: repetir o que já está cadastrado não é alteração. O histórico ganha uma
 * fonte, não uma versão nova do campo.
 */
it('records a repeated value as a new source rather than a change', function (): void {
    [, $guarantee, $candidate] = worksFundScenario([
        'identification' => [
            'bank' => 'Bradesco',
            'agency' => '7748',
            'account' => '185187-P',
        ],
    ]);

    $plan = app(GuaranteeConsolidationPlanner::class)->plan($candidate, $guarantee);

    expect($plan->outcome)->toBe(GuaranteeReconciliationOutcome::Confirmation)
        ->and($plan->complements)->toBeEmpty()
        ->and($plan->divergences)->toBeEmpty()
        ->and(collect($plan->confirmations)->pluck('label')->all())->toContain('Conta');

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    $guarantee->refresh();

    expect($guarantee->identification['account'])->toBe('185187-P')
        ->and($guarantee->documentReferences()->sole()->reference_type)
        ->toBe(GuaranteeDocumentReferenceType::Constitution);
});

/**
 * §2: uma conta diferente da cadastrada não é atualização automática. O
 * documento apenas constitui — não diz que substitui —, então alguém decide.
 */
it('flags a divergent value for review instead of overwriting it', function (): void {
    [, $guarantee, $candidate] = worksFundScenario([
        'identification' => ['account' => '185187-0'],
    ]);

    $plan = app(GuaranteeConsolidationPlanner::class)->plan($candidate, $guarantee);

    expect($plan->outcome)->toBe(GuaranteeReconciliationOutcome::Conflict)
        ->and(collect($plan->divergences)->pluck('label')->all())->toContain('Conta');

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    expect($guarantee->refresh()->identification['account'])->toBe('185187-0')
        ->and($guarantee->identification['bank'])->toBe('Banco Bradesco S.A. (cód. 237)');
});

/**
 * §11: aceitando a divergência, a posição anterior fica registrada no evento —
 * não é sobrescrita em silêncio.
 */
it('preserves the previous value in history when the reviewer accepts the change', function (): void {
    [, $guarantee, $candidate] = worksFundScenario(
        ['contracted_value' => 1_000_000],
        ['contracted_value' => 1_500_000, 'event_type' => GuaranteeEventType::Amendment],
    );

    app(GuaranteeSuggestionReviewService::class)->complement(
        $candidate,
        makeAdminUser(),
        ['contracted_value' => GuaranteeSuggestionReviewService::DECISION_UPDATE],
    );

    $guarantee->refresh();

    expect((float) $guarantee->contracted_value)->toBe(1_500_000.0);

    $event = $guarantee->events()->sole();

    expect($event->event_type)->toBe(GuaranteeEventType::Amendment)
        ->and((float) $event->previous_values['contracted_value'])->toBe(1_000_000.0)
        ->and((float) $event->new_values['contracted_value'])->toBe(1_500_000.0)
        ->and($event->effective_date?->toDateString())->toBe('2026-05-14');
});

it('keeps the current value when the reviewer decides to keep it', function (): void {
    [, $guarantee, $candidate] = worksFundScenario(
        ['contracted_value' => 1_000_000],
        ['contracted_value' => 1_500_000, 'event_type' => GuaranteeEventType::Amendment],
    );

    app(GuaranteeSuggestionReviewService::class)->complement(
        $candidate,
        makeAdminUser(),
        ['contracted_value' => GuaranteeSuggestionReviewService::DECISION_KEEP],
    );

    expect((float) $guarantee->refresh()->contracted_value)->toBe(1_000_000.0)
        ->and($guarantee->events()->sole()->event_type)->toBe(GuaranteeEventType::Amendment);
});

/**
 * §8: criar continua possível — podem existir duas reservas de obras — mas
 * deixa de ser a saída padrão quando há correspondência.
 */
it('still allows creating a distinct guarantee when the reviewer disagrees', function (): void {
    [, $guarantee, $candidate] = worksFundScenario();

    $created = app(GuaranteeSuggestionReviewService::class)->approve($candidate, makeAdminUser());

    expect($created->is($guarantee))->toBeFalse()
        ->and(Guarantee::query()->count())->toBe(2)
        ->and($created->name)->toBe('Fundo de Obras');
});

/**
 * §16: a conta bancária já existe como fundo da emissão, então a garantia
 * aponta para ele em vez de guardar uma segunda cópia dos dados.
 */
it('links the guarantee to the fund that already holds the account', function (): void {
    [$emission, $guarantee, $candidate] = worksFundScenario();

    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'bank_id' => Bank::factory()->create(['name' => 'Bradesco'])->id,
        'agency' => '7748',
        'account' => '185187-P',
    ]);

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    expect($guarantee->refresh()->fund_id)->toBe($fund->id)
        ->and(Fund::query()->count())->toBe(1);

    // §16: os dados bancários ficam no fundo, não numa segunda cópia dentro da
    // garantia — uma conta só, atualizada num lugar só.
    expect($guarantee->identification ?? [])->not->toHaveKey('account')
        ->and($guarantee->identification ?? [])->not->toHaveKey('agency')
        ->and($guarantee->identification ?? [])->not->toHaveKey('bank');

    $detail = view('filament.resources.emissions.relation-managers.guarantee-detail', [
        'guarantee' => $guarantee->load(['documentReferences', 'events.documentReference', 'fund.bank', 'fund.fundName']),
        'position' => null,
    ])->render();

    expect($detail)->toContain('Conta vinculada')
        ->and($detail)->toContain('Ag. 7748')
        ->and($detail)->toContain('C/C 185187-P');
});

it('records the consolidation in the audit trail', function (): void {
    [, $guarantee, $candidate] = worksFundScenario();
    $actor = makeAdminUser();

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, $actor, [], 'Conferido contra a CCB.');

    $activity = Activity::query()
        ->where('log_name', GuaranteeSuggestionReviewService::LOG_NAME)
        ->where('event', GuaranteeSuggestionReviewService::EVENT_COMPLEMENTED)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->properties['guarantee_id'])->toBe($guarantee->id)
        ->and($activity->properties['outcome'])->toBe(GuaranteeReconciliationOutcome::Complement->value)
        ->and($activity->properties['review_notes'])->toBe('Conferido contra a CCB.')
        ->and(collect($activity->properties['added_fields'])->pluck('label')->all())->toContain('Conta')
        ->and($activity->properties['source']['clause'])->toBe('10.3 (a)');
});

it('refuses to complement without a matching guarantee', function (): void {
    $emission = Emission::factory()->create();

    $candidate = ExtractedGuarantee::factory()->worksFund()->create([
        'emission_id' => $emission->id,
        'related_guarantee_id' => null,
    ]);

    expect(fn () => app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser()))
        ->toThrow(ValidationException::class);
});

/**
 * A garantia pode ter sido cadastrada depois da detecção. A ausência de
 * correspondência naquele momento não é motivo para criar uma duplicata agora.
 */
it('complements a guarantee registered after the detection ran', function (): void {
    $emission = Emission::factory()->create();

    $candidate = ExtractedGuarantee::factory()->worksFund()->create([
        'emission_id' => $emission->id,
        'related_guarantee_id' => null,
        'reconciliation_outcome' => GuaranteeReconciliationOutcome::NewGuarantee,
    ]);

    $guarantee = Guarantee::factory()
        ->ofType(GuaranteeType::WorksFund)
        ->create([
            'emission_id' => $emission->id,
            'name' => 'Reserva de Obras',
            'identification' => null,
        ]);

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    expect(Guarantee::query()->count())->toBe(1)
        ->and($guarantee->refresh()->identification['account'])->toBe('185187-P')
        ->and($candidate->refresh()->related_guarantee_id)->toBe($guarantee->id);
});

it('refuses to complement without the permission', function (): void {
    [, $guarantee, $candidate] = worksFundScenario();

    expect(fn () => app(GuaranteeSuggestionReviewService::class)->complement($candidate, User::factory()->create()))
        ->toThrow(AuthorizationException::class);

    expect($candidate->refresh()->status)->toBe(GuaranteeDetectionStatus::Suggested)
        ->and($guarantee->refresh()->identification)->toBeNull();
});

it('shows the complement wording instead of the conflict banner', function (): void {
    [, , $candidate] = worksFundScenario();

    $modal = view('filament.resources.emissions.relation-managers.guarantee-detection-review', [
        'candidate' => $candidate->load(['document', 'relatedGuarantee']),
        'plan' => app(GuaranteeSuggestionReviewService::class)->planFor($candidate),
    ])->render();

    expect($modal)->toContain('Informações complementares encontradas')
        ->and($modal)->not->toContain('Conflito documental')
        ->and($modal)->toContain('Reserva de Obras')
        ->and($modal)->toContain('185187-P')
        ->and($modal)->toContain('Complementa');
});

/**
 * §4: a linha do tempo tem de responder "de onde veio esta conta bancária?".
 *
 * A identificação é gravada como um JSON só. Sem abri-la campo a campo, o
 * histórico dizia "Identification: Array → Array" — e, pior, quebrava a
 * renderização da aba de detalhe assim que uma conta era complementada.
 */
it('shows each complemented identification field with its source in the timeline', function (): void {
    [, $guarantee, $candidate] = worksFundScenario();

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    $event = $guarantee->refresh()->events()->sole();
    $changes = collect($event->change_summary);

    expect($changes->pluck('label')->all())->toContain('Conta', 'Banco', 'Agência');

    $account = $changes->firstWhere('field', 'account');

    expect($account['from'])->toBeNull()
        ->and($account['to_display'])->toBe('185187-P')
        ->and($event->documentReference->document_label)->toBe('Cédula de Crédito Bancário')
        ->and($event->documentReference->location_label)->toBe('Cláusula 10.3 (a) · Página 23');

    $detail = view('filament.resources.emissions.relation-managers.guarantee-detail', [
        'guarantee' => $guarantee->load(['documentReferences', 'events.documentReference', 'fund']),
        'position' => null,
    ])->render();

    expect($detail)->toContain('Conta:')
        ->and($detail)->toContain('185187-P')
        ->and($detail)->toContain('Cláusula 10.3 (a) · Página 23');
});

/**
 * §16 sem perda de rastreabilidade: mesmo sem a conta na `identification` da
 * garantia, o sistema continua respondendo de onde ela veio.
 */
it('keeps the documentary source of bank data delegated to the fund', function (): void {
    [$emission, $guarantee, $candidate] = worksFundScenario();

    Fund::factory()->create([
        'emission_id' => $emission->id,
        'bank_id' => Bank::factory()->create(['name' => 'Bradesco'])->id,
        'agency' => '7748',
        'account' => '185187-P',
    ]);

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    $account = $guarantee->refresh()->instrumentFields
        ->firstWhere('field_key', LegalInstrumentFieldKey::AccountNumber);

    expect($account)->not->toBeNull()
        ->and($account->value)->toBe('185187-P')
        ->and($account->status)->toBe(LegalInstrumentFieldStatus::Confirmed)
        ->and($account->clause)->toBe('10.3 (a)')
        ->and($account->page)->toBe(23)
        ->and($account->effective_date?->toDateString())->toBe('2026-05-14');
});

/**
 * §11: o valor anterior não é substituído em silêncio — ele deixa de vigorar na
 * data em que o seguinte passa a valer, e os dois ficam consultáveis.
 */
it('versions a changed field with its validity window and source', function (): void {
    [$emission, $guarantee, $firstCandidate] = worksFundScenario();
    $actor = makeAdminUser();

    app(GuaranteeSuggestionReviewService::class)->complement($firstCandidate, $actor);

    $amendmentDocument = Document::factory()->create(['title' => '1º Aditamento']);

    $secondCandidate = ExtractedGuarantee::factory()
        ->worksFund(['account' => '987654-2'])
        ->create([
            'emission_id' => $emission->id,
            'document_id' => $amendmentDocument->id,
            'related_guarantee_id' => $guarantee->id,
            'event_type' => GuaranteeEventType::Amendment,
            'document_date' => '2026-05-15',
            'effective_date' => '2026-05-15',
            'source_clause' => '5.1',
            'source_page' => 4,
        ]);

    app(GuaranteeSuggestionReviewService::class)->complement(
        $secondCandidate,
        $actor,
        ['account' => GuaranteeSuggestionReviewService::DECISION_UPDATE],
    );

    $timeline = app(GuaranteeFieldVersionWriter::class)->timeline($guarantee->refresh()->load('instrumentFields'));
    $account = $timeline->get(LegalInstrumentFieldKey::AccountNumber->value);

    expect($account['versions'])->toHaveCount(2);

    [$current, $previous] = $account['versions'];

    expect($current['field']->value)->toBe('987654-2')
        ->and($current['is_current'])->toBeTrue()
        ->and($current['valid_from']?->toDateString())->toBe('2026-05-15')
        ->and($current['valid_until'])->toBeNull()
        ->and($current['field']->document_label)->toBe('1º Aditamento');

    expect($previous['field']->value)->toBe('185187-P')
        ->and($previous['is_current'])->toBeFalse()
        ->and($previous['valid_from']?->toDateString())->toBe('2026-05-14')
        ->and($previous['valid_until']?->toDateString())->toBe('2026-05-15')
        ->and($previous['field']->status)->toBe(LegalInstrumentFieldStatus::Superseded)
        ->and($previous['field']->document_label)->toBe('Cédula de Crédito Bancário');

    expect($guarantee->identification['account'])->toBe('987654-2');
});

it('shows the validity window of each field on the guarantee detail', function (): void {
    [$emission, $guarantee, $candidate] = worksFundScenario();

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    $detail = view('filament.resources.emissions.relation-managers.guarantee-detail', [
        'guarantee' => $guarantee->fresh()->load(['documentReferences', 'events.documentReference', 'fund', 'instrumentFields.document']),
        'position' => null,
    ])->render();

    expect($detail)->toContain('Vigência por campo')
        ->and($detail)->toContain('Vigente desde 14/05/2026')
        ->and($detail)->toContain('185187-P')
        ->and($detail)->toContain('Cláusula 10.3 (a) · Página 23');
});

/**
 * Uma garantia identificada num documento avulso — sem CCB nem contrato
 * cadastrado — também tem vigência por campo. Era o que a coluna obrigatória
 * de instrumento impedia.
 */
it('versions fields of a guarantee that has no legal instrument', function (): void {
    [, $guarantee, $candidate] = worksFundScenario();

    expect($guarantee->legal_instrument_id)->toBeNull()
        ->and($candidate->legal_instrument_id)->toBeNull();

    app(GuaranteeSuggestionReviewService::class)->complement($candidate, makeAdminUser());

    $versions = $guarantee->refresh()->instrumentFields;

    expect($versions)->not->toBeEmpty()
        ->and($versions->every(fn ($field): bool => $field->legal_instrument_id === null))->toBeTrue()
        ->and($versions->pluck('field_key')->all())->toContain(LegalInstrumentFieldKey::AccountNumber);
});
