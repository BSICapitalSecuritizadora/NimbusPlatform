<?php

use App\Enums\ContractStatus;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Models\ContractInstallment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;

/**
 * "Verificar alterações" numa competência aprovada e publicada: a posição
 * publicada não se recalcula, e a orientação manda o fato para o extemporâneo
 * da competência seguinte -- ou para a retificação, só na última publicada.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * A notificação da verificação, lida da sessão antes de `assertNotified`.
 *
 * @return array{title: string, body: string}
 */
function staleGuidanceNotification(): array
{
    $notification = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])->last();

    return ['title' => (string) ($notification['title'] ?? ''), 'body' => strip_tags((string) ($notification['body'] ?? ''))];
}

it('sends a late fact of the last published competence to the next one or to the rectification, never to a recalculation', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18');

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['july']->getKey()])
        ->callAction('checkStale')
        ->assertHasNoActionErrors();

    $july = staleGuidanceNotification();

    expect($july['title'])->toBe('Alterações materiais')
        ->and($july['body'])->toContain('A posição publicada de 07/2026 não é recalculada: os fatos alterados depois da publicação entram como movimentos extemporâneos na próxima competência a ser publicada (08/2026)')
        ->and($july['body'])->toContain('Para corrigir a posição publicada, use “Retificar competência”.')
        ->and($july['body'])->not->toContain('precisa ser recalculada');

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['july']->getKey()])
        ->assertSee('A fonte mudou depois da publicação, e a posição publicada não é recalculada')
        ->assertActionDisabled('recalculate');

    // Agosto publicado: julho deixa de ser a última e não é mais retificável.
    ExtemporaneousFixture::publish(ExtemporaneousFixture::generateAugust($scenario['construction']));

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['july']->getKey()])
        ->callAction('checkStale');

    $older = staleGuidanceNotification();

    expect($older['title'])->toBe('Alterações materiais')
        ->and($older['body'])->toContain('os fatos alterados antes da publicação de 08/2026 já entraram nela como movimentos extemporâneos, e os posteriores entram na próxima competência a ser publicada (09/2026)')
        ->and($older['body'])->not->toContain('Retificar competência')
        ->and($older['body'])->not->toContain('precisa ser recalculada');
});

it('says there is nothing to do when only the source of a published competence changed', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);
    $cancelled = DerivationFixture::contract($units[0], '2026-03-01', '500000.00', cancellationDate: '2026-05-10', status: ContractStatus::Cancelled);
    $installment = DerivationFixture::installment($cancelled, '001', '2026-04-10', '500000.00');
    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    ExtemporaneousFixture::publish($july);

    $installment->update(['expected_value' => '499000.00']);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $july->getKey()])
        ->callAction('checkStale');

    expect(staleGuidanceNotification())->toBe([
        'title' => 'Fonte alterada sem impacto',
        'body' => 'A fonte mudou depois da publicação sem alterar a posição publicada: nada a fazer.',
    ]);
});

it('keeps the published position when the source of a published competence is incomplete', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    ContractInstallment::query()->where('contract_id', $scenario['financed']->id)->delete();

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['july']->getKey()])
        ->callAction('checkStale');

    $notification = staleGuidanceNotification();

    expect($notification['title'])->toBe('Fonte atual incompleta')
        ->and($notification['body'])->toContain('A posição publicada continua valendo; a próxima competência só será apurada quando o dado faltante for cadastrado.')
        ->and($notification['body'])->not->toContain('nenhuma versão nova pode ser calculada');
});

it('shows the approved analysis without deriving the source and without the publication gate', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();

    // A fonte muda depois da publicação: o portão ao vivo diria "bloqueada".
    ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['july']->getKey()])
        ->assertOk()
        ->assertSee('Aprovada e publicada em')
        ->assertDontSee('Publicação bloqueada');

    // A ação depende só da permissão e recusa ao abrir com o motivo; quem não
    // oferece o botão numa análise encerrada é a tela.
    expect(preg_match('/wire:click="mountAction\((?:\'|&#0?39;)approve(?:\'|&#0?39;)[,)]/', $page->html()))->toBe(0);

    $sourceReads = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => preg_match('/\b(from|join)\s+"(contracts|contract_installments)"/', strtolower($sql)) === 1);
    DB::disableQueryLog();

    expect($sourceReads)->toBeEmpty();
});
