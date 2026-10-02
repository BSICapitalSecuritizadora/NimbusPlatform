<?php

use App\Enums\AccessPermission;
use App\Enums\MalwareScanStatus;
use App\Enums\SalesBoardBuilderResponseChannel;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Models\SalesBoardBuilderReviewAttachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * A resposta da construtora pela tela: registrada no envio, exibida na
 * Validação e na Análise, e baixada só por quem enxerga o Quadro.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * Quem só consulta o Quadro: vê, mas não opera nem aprova.
 */
function evidenceViewer(bool $canViewSalesBoard = true): User
{
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->givePermissionTo($canViewSalesBoard
        ? [AccessPermission::SalesBoardsView->value, AccessPermission::EmissionsView->value]
        : [AccessPermission::EmissionsView->value]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

/**
 * Um anexo limpo, gravado de verdade no disco privado falso.
 */
function storedEvidenceAttachment(MalwareScanStatus $status = MalwareScanStatus::Clean, bool $writeFile = true): SalesBoardBuilderReviewAttachment
{
    $scenario = ManagementReviewFixture::submittedCycle();
    $attachment = $scenario['builderReview']->attachments()->sole();

    $path = 'nimbus_docs/sales-board-builder-responses/'.$scenario['cycle']->id.'/resposta-'.$attachment->id.'.pdf';

    if ($writeFile) {
        Storage::disk('local')->put($path, "%PDF-1.4\n% Resposta da construtora\n%%EOF\n");
    }

    // O anexo é imutável pelo model; o cenário só aponta o caminho e a
    // varredura para o arquivo deste teste.
    DB::table('sales_board_builder_review_attachments')
        ->where('id', $attachment->id)
        ->update(['path' => $path, 'scan_status' => $status->value]);

    return $attachment->fresh();
}

it('submits the review with the builder response from the workspace', function () {
    Storage::fake('local');

    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('submitReview', [
            'builder_respondent_name' => 'Carlos Menezes',
            'builder_respondent_email' => 'carlos.menezes@construtora.example',
            'builder_response_channel' => SalesBoardBuilderResponseChannel::MeetingMinutes->value,
            'builder_response_received_on' => '2026-08-04',
            'builder_response_attachments' => [
                UploadedFile::fake()->createWithContent('ata-reuniao.pdf', "%PDF-1.4\n% Ata da reunião com a construtora\n%%EOF\n"),
                UploadedFile::fake()->createWithContent('posicao-conferida.pdf', "%PDF-1.4\n% Posição conferida\n%%EOF\n"),
            ],
            'declaration' => true,
        ])
        ->assertHasNoActionErrors();

    $submitted = $review->fresh();

    expect($submitted->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($submitted->builder_respondent_name)->toBe('Carlos Menezes')
        ->and($submitted->builder_response_channel)->toBe(SalesBoardBuilderResponseChannel::MeetingMinutes)
        ->and($submitted->builder_response_received_on->toDateString())->toBe('2026-08-04')
        ->and($submitted->attachments->pluck('original_name')->all())->toBe(['ata-reuniao.pdf', 'posicao-conferida.pdf'])
        ->and($submitted->attachments->every(fn (SalesBoardBuilderReviewAttachment $attachment): bool => Storage::disk('local')->exists($attachment->path)))->toBeTrue();

    $page->assertSee('Resposta de')
        ->assertSee('Carlos Menezes')
        ->assertSee('Reunião registrada em ata')
        ->assertSee('recebida em 04/08/2026')
        ->assertSee('ata-reuniao.pdf');
});

it('refuses the submission in the form when the builder response is missing', function () {
    Storage::fake('local');

    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('submitReview', [
            'builder_response_channel' => SalesBoardBuilderResponseChannel::Other->value,
            'declaration' => true,
        ])
        ->assertHasActionErrors([
            'builder_respondent_name',
            'builder_respondent_email',
            'builder_response_attachments',
            'overall_comment',
        ]);

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('shows the builder response and its attachments on the management review', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    $attachment = $scenario['builderReview']->attachments()->sole();

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Resposta da construtora')
        ->assertSee('Registrada internamente por')
        ->assertSee('Resposta de')
        ->assertSee('Marina Ribeiro')
        ->assertSee('marina.ribeiro@construtora.example')
        ->assertSee('E-mail')
        ->assertSee('recebida em 31/07/2026')
        ->assertSee('resposta-construtora.pdf')
        ->assertSee(route('admin.sales-board-builder-responses.download', ['attachment' => $attachment->id]), escape: false);
});

/**
 * A Análise mostra os instantes no fuso de negócio, como a Validação: o envio
 * no cabeçalho e no registro da resposta, e a decisão de cada pendência. Entre
 * 21h e meia-noite de Brasília o dia UTC já virou, e a mesma tela mostraria
 * dois dias para o mesmo envio.
 */
it('shows the submission and the decisions of the analysis in the business timezone', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $management = ManagementReviewFixture::open($scenario['cycle']);

    $nonconformity = ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($management, SalesBoardNonconformityOrigin::SystemSaleNonConform),
        SalesBoardNonconformityDecision::AcceptedException,
    );

    // 01:30 e 02:10 UTC de 05/08 são 22:30 e 23:10 de 04/08 em Brasília.
    DB::table('sales_board_builder_reviews')->where('id', $scenario['builderReview']->id)->update(['submitted_at' => '2026-08-05 01:30:00']);
    DB::table('sales_board_management_nonconformities')->where('id', $nonconformity->id)->update(['decided_at' => '2026-08-05 02:10:00']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('em 04/08/2026 22:30 ·')
        ->assertSee('Registrada internamente por')
        ->assertSee('04/08/2026 23:10')
        ->assertDontSee('05/08/2026 01:30')
        ->assertDontSee('05/08/2026 02:10');
});

it('warns when a submitted round has no builder response attached', function () {
    $scenario = ManagementReviewFixture::submittedCycle();

    // Uma rodada enviada antes da exigência de evidência: colunas nulas e
    // nenhum anexo.
    DB::table('sales_board_builder_review_attachments')
        ->where('sales_board_builder_review_id', $scenario['builderReview']->id)
        ->delete();
    DB::table('sales_board_builder_reviews')->where('id', $scenario['builderReview']->id)->update([
        'builder_respondent_name' => null,
        'builder_respondent_email' => null,
        'builder_response_channel' => null,
        'builder_response_received_on' => null,
    ]);

    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Nenhuma resposta da construtora anexada (validação registrada antes da exigência de evidência).')
        ->assertSee('Pronto para publicação')
        ->assertActionVisible('approve')
        ->assertActionEnabled('approve');

    $this->get(BuilderReviewWorkspace::getUrl(['record' => $scenario['cycle']]).'?review='.$scenario['builderReview']->id)
        ->assertOk()
        ->assertSee('Nenhuma resposta da construtora anexada (validação registrada antes da exigência de evidência).');
});

it('downloads a clean attachment for a user who views the sales board', function () {
    Storage::fake('local');
    $attachment = storedEvidenceAttachment();

    $this->actingAs(evidenceViewer());

    $response = $this->get(route('admin.sales-board-builder-responses.download', ['attachment' => $attachment->id]))
        ->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('resposta-construtora.pdf')
        ->and($response->streamedContent())->toBe("%PDF-1.4\n% Resposta da construtora\n%%EOF\n");
});

it('denies the download without sales-boards.view and hides an unscanned or missing file', function () {
    Storage::fake('local');
    $attachment = storedEvidenceAttachment();
    $url = route('admin.sales-board-builder-responses.download', ['attachment' => $attachment->id]);

    $this->actingAs(evidenceViewer(canViewSalesBoard: false));
    $this->get($url)->assertForbidden();

    $this->actingAs(evidenceViewer());
    $this->get($url)->assertOk();

    DB::table('sales_board_builder_review_attachments')
        ->where('id', $attachment->id)
        ->update(['scan_status' => MalwareScanStatus::Pending->value]);

    $this->get($url)->assertNotFound();

    DB::table('sales_board_builder_review_attachments')
        ->where('id', $attachment->id)
        ->update(['scan_status' => MalwareScanStatus::Clean->value]);
    Storage::disk('local')->delete($attachment->path);

    $this->get($url)->assertNotFound();
});
