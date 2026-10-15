<?php

use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Measurement;
use App\Models\User;
use App\Services\DocumentStorageService;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementFileValidationService;
use App\Services\MeasurementFinancialReconciliationService;
use App\Services\MeasurementPhysicalProgressService;
use App\Services\MeasurementRevisionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementRevisionScenario as Scenario;

/**
 * As referências entre as revisões de uma medição, nas seções Revisões da
 * medição e Enquadramento financeiro: link só para a página de verdade de
 * quem pode vê-la, e texto legível -- nunca um link de mentira -- quando não
 * há página a que levar.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * R0 paga por R$ 100.000,00 e finalizada; a R1 (R$ 80.000,00) reconheceu na
 * Finalização os R$ 20.000,00 pagos a maior; a R2 (R$ 70.000,00) é a vigente,
 * na etapa Pagamento, com R$ 30.000,00 pagos a maior em aberto.
 *
 * @return array{scenario: array<string, mixed>, r0: Measurement, r1: Measurement, r2: Measurement}
 */
function revisionReferenceFamily(): array
{
    $scenario = Scenario::plan();
    $r0 = Scenario::finalized($scenario, '2026-05', 10);
    $r1 = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $r0, 8), 'Decisão da R1: valor pago a maior tratado fora do sistema.', acceptFinancialExceptions: true);
    $r2 = Scenario::effective($scenario, $r1, 7);

    return ['scenario' => $scenario, 'r0' => $r0->fresh(), 'r1' => $r1->fresh(), 'r2' => $r2->fresh()];
}

/**
 * O serviço de revisões de verdade, com a resposta trocada no ponto indicado:
 * o reconhecimento anterior atribuído a uma medição que não existe, ou a
 * família sem endereço de página.
 */
function revisionReferenceServiceWith(string $change): MeasurementRevisionService
{
    return new class($change, app(MeasurementAuthorizationService::class), app(MeasurementPhysicalProgressService::class), app(MeasurementFinancialReconciliationService::class), app(DocumentStorageService::class), app(MeasurementFileValidationService::class)) extends MeasurementRevisionService
    {
        public function __construct(
            private readonly string $change,
            MeasurementAuthorizationService $authorization,
            MeasurementPhysicalProgressService $physicalProgress,
            MeasurementFinancialReconciliationService $reconciliation,
            DocumentStorageService $storage,
            MeasurementFileValidationService $fileValidation,
        ) {
            parent::__construct($authorization, $physicalProgress, $reconciliation, $storage, $fileValidation);
        }

        public function positions(Measurement $revision, ?array $snapshot = null, ?Measurement $previous = null): array
        {
            return array_map(
                fn (MeasurementRevisionPlanSetPosition $position): MeasurementRevisionPlanSetPosition => $this->change !== 'unknown acknowledging measurement' || $position->previouslyAcknowledgedByMeasurementIds === []
                    ? $position
                    : new MeasurementRevisionPlanSetPosition(...[...get_object_vars($position), 'previouslyAcknowledgedByMeasurementIds' => [999999]]),
                parent::positions($revision, $snapshot, $previous),
            );
        }

        public function overview(Measurement $measurement): array
        {
            $overview = parent::overview($measurement);

            if ($this->change === 'pages without address') {
                $overview['family'] = array_map(fn (array $member): array => ['url' => null] + $member, $overview['family']);
                $overview['superseded_by'] = $overview['superseded_by'] === null ? null : ['url' => null] + $overview['superseded_by'];
                $overview['family_position'] = $overview['family_position'] === null ? null : ['url' => null] + $overview['family_position'];
            }

            return $overview;
        }
    };
}

it('names the earlier revision that acknowledged the overpayment by its label, as text', function () {
    ['scenario' => $scenario, 'r2' => $r2] = revisionReferenceFamily();
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $r2->getRouteKey()])
        ->assertSee('reconhecido anteriormente R$ 20.000,00 (R1)')
        ->assertSee('Reconhecido anteriormente (R1)')
        ->assertDontSeeHtml('href="#"');
});

it('names an acknowledging measurement missing from the family as readable text, never as a placeholder link', function () {
    ['scenario' => $scenario, 'r2' => $r2] = revisionReferenceFamily();
    app()->instance(MeasurementRevisionService::class, revisionReferenceServiceWith('unknown acknowledging measurement'));
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $r2->getRouteKey()])
        ->assertSee('reconhecido anteriormente R$ 20.000,00 (medição #999999)')
        ->assertSee('Reconhecido anteriormente (medição #999999)')
        ->assertDontSeeHtml('medição #999999</a>')
        ->assertDontSeeHtml('href="#"');
});

it('links the other revisions of the family to their own pages, and the superseded one to the revision that replaced it', function () {
    ['scenario' => $scenario, 'r0' => $r0, 'r1' => $r1, 'r2' => $r2] = revisionReferenceFamily();
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $r2->getRouteKey()])
        ->assertSeeHtml('href="'.MeasurementResource::getUrl('view', ['record' => $r0]).'"')
        ->assertSeeHtml('href="'.MeasurementResource::getUrl('view', ['record' => $r1]).'"');

    Livewire::test(ViewMeasurement::class, ['record' => $r1->getRouteKey()])
        ->assertSee('Substituída pela revisão')
        ->assertSeeHtml('href="'.MeasurementResource::getUrl('view', ['record' => $r2]).'" class="font-semibold')
        ->assertSee('Abrir a revisão R2');
});

it('shows a revision whose page has no address as text instead of a link', function () {
    ['scenario' => $scenario, 'r0' => $r0, 'r1' => $r1, 'r2' => $r2] = revisionReferenceFamily();
    app()->instance(MeasurementRevisionService::class, revisionReferenceServiceWith('pages without address'));
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $r1->getRouteKey()])
        ->assertSee('Substituída pela revisão')
        ->assertSee('R0 · Original')
        ->assertSeeHtml('<span class="font-semibold">R2</span>')
        ->assertDontSee('Abrir a revisão R2')
        ->assertDontSeeHtml('href="'.MeasurementResource::getUrl('view', ['record' => $r0]).'"')
        ->assertDontSeeHtml('href="'.MeasurementResource::getUrl('view', ['record' => $r2]).'"')
        ->assertDontSeeHtml('href=""')
        ->assertDontSeeHtml('href="#"');
});

it('links only revisions of the same family and operation, which whoever sees the page can open, and shows the page to no one outside the operation', function () {
    ['scenario' => $scenario, 'r0' => $r0, 'r1' => $r1, 'r2' => $r2] = revisionReferenceFamily();
    // Uma medição de outra operação, que o participante não pode abrir.
    $foreign = Measurement::factory()->create();
    $participant = User::factory()->withTwoFactor()->create();
    $participant->givePermissionTo('measurements.view');
    $scenario['operation']->forceFill(['stage2_reviewer_user_id' => $participant->id])->save();
    $outsider = User::factory()->withTwoFactor()->create();
    $outsider->givePermissionTo('measurements.view');

    $this->actingAs($participant);
    $html = Livewire::test(ViewMeasurement::class, ['record' => $r2->getRouteKey()])->html();
    preg_match_all('#href="'.preg_quote(MeasurementResource::getUrl('index'), '#').'/(\d+)"#', $html, $links);
    $linked = Measurement::query()->whereKey(array_unique(array_map('intval', $links[1])))->get();

    // Ver uma revisão é ver a operação: os links só levam à mesma família, na
    // mesma operação -- e por isso a quem pode abrir cada um deles.
    expect($linked->modelKeys())->toContain($r0->id, $r1->id)
        ->and($linked->modelKeys())->not->toContain($foreign->id)
        ->and($participant->can('view', $foreign))->toBeFalse()
        ->and($linked->reject(fn (Measurement $member): bool => (int) $member->revision_family_id === (int) $r2->revision_family_id
            && (int) $member->operation_id === (int) $r2->operation_id)->modelKeys())->toBe([])
        ->and($linked->reject(fn (Measurement $member): bool => $participant->can('view', $member))->modelKeys())->toBe([]);

    // Fora da operação, a medição nem aparece: a página não confirma que ela
    // existe, e nenhum link da família chega a quem não poderia abri-lo.
    $this->actingAs($outsider);

    Livewire::test(ViewMeasurement::class, ['record' => $r2->getRouteKey()])->assertNotFound();
});
