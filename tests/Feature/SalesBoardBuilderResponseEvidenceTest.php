<?php

use App\DTOs\SalesBoards\BuilderResponseEvidence;
use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\Enums\AccessPermission;
use App\Enums\MalwareScanStatus;
use App\Enums\SalesBoardBuilderResponseChannel;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewAttachment;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardBuilderResponseEvidenceStore;
use App\Services\SalesBoards\SalesBoardBuilderReviewSubmissionService;
use App\Services\Security\ClamAvFileScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\GovernanceFixture;

/**
 * A resposta da construtora que sustenta o envio interno da validação.
 *
 * O envio interno é o registro da resposta: quem respondeu pela construtora, o
 * canal, a data do recebimento e de 1 a 5 arquivos. A regra mora no serviço --
 * uma chamada direta passa por ela como a tela --, os arquivos vão para o disco
 * privado depois da varredura, com os metadados derivados do que foi gravado,
 * e qualquer recusa apaga o que já tinha sido gravado.
 */
uses(RefreshDatabase::class);

/**
 * Uma rodada pronta para enviar: as sete seções confirmadas.
 */
function evidenceReadyReview(): SalesBoardBuilderReview
{
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    return $review->fresh();
}

/**
 * Envia pela própria API do serviço, com o armazenamento real.
 */
function submitWithEvidence(SalesBoardBuilderReview $review, ?BuilderResponseEvidence $evidence, ?User $actor = null, ?string $comment = null): SalesBoardBuilderReview
{
    return app(SalesBoardBuilderReviewSubmissionService::class)->submit(
        $review->fresh(),
        BuilderReviewerIdentity::forInternalUser($actor ?? GovernanceFixture::operator()),
        $comment,
        $evidence,
    );
}

/**
 * Um scanner que responde o veredito pedido, ligado no container.
 */
function bindEvidenceScanner(string $verdict): void
{
    app()->instance(ClamAvFileScanner::class, new class($verdict) extends ClamAvFileScanner
    {
        public function __construct(private readonly string $verdict) {}

        public function isEnabled(): bool
        {
            return true;
        }

        public function scanStream(mixed $fileStream): string
        {
            return $this->verdict;
        }
    });
}

it('requires the builder response to submit an internal review', function () {
    Storage::fake('local');
    $review = evidenceReadyReview();

    expect(fn () => submitWithEvidence($review, null))
        ->toThrow(SalesBoardBuilderReviewException::class, SalesBoardBuilderReviewException::builderResponseEvidenceRequired()->getMessage());

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and(SalesBoardBuilderReviewAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    // O par: com a resposta, o mesmo envio passa.
    expect(submitWithEvidence($review, BuilderReviewFixture::evidence($review))->status)
        ->toBe(SalesBoardBuilderReviewStatus::Submitted);
});

it('stores the response on the private disk and freezes it with the submission', function () {
    Storage::fake('local');
    $review = evidenceReadyReview();
    $actor = GovernanceFixture::operator();
    $content = "%PDF-1.4\n% Resposta enviada pela construtora em 05/08/2026\n%%EOF\n";

    $submitted = submitWithEvidence($review, BuilderReviewFixture::evidence(
        $review,
        receivedOn: '2026-08-05',
        files: [UploadedFile::fake()->createWithContent('Resposta Construtora 07-2026.pdf', $content)],
    ), $actor);

    $attachment = $submitted->attachments->sole();

    expect($submitted->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($submitted->declaration_version)->toBe('2026-10-v2')
        ->and($submitted->builder_respondent_name)->toBe('Marina Ribeiro')
        ->and($submitted->builder_respondent_email)->toBe('marina.ribeiro@construtora.example')
        ->and($submitted->builder_response_channel)->toBe(SalesBoardBuilderResponseChannel::Email)
        ->and($submitted->builder_response_received_on->toDateString())->toBe('2026-08-05')
        ->and($attachment->disk)->toBe('local')
        ->and($attachment->path)->toStartWith('nimbus_docs/sales-board-builder-responses/'.$review->sales_board_cycle_id.'/')
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue()
        ->and(Storage::disk('local')->get($attachment->path))->toBe($content)
        ->and($attachment->checksum)->toBe(hash('sha256', $content))
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->size_bytes)->toBe(strlen($content))
        ->and($attachment->original_name)->toBe('Resposta Construtora 07-2026.pdf')
        ->and($attachment->scan_status)->toBe(MalwareScanStatus::Clean)
        ->and($attachment->uploaded_by_user_id)->toBe($actor->id);
});

it('refuses a received date outside the position date and the business today', function (string $case) {
    // 02:00 UTC de 10/08 ainda é 09/08 em Brasília: "amanhã" é 10/08.
    $this->travelTo(CarbonImmutable::parse('2026-08-10 02:00:00', 'UTC'));

    $review = evidenceReadyReview();
    BuilderReviewFixture::useInMemoryEvidenceStore();

    $receivedOn = match ($case) {
        'antes da posição' => '2026-07-30',
        'amanhã' => '2026-08-10',
        'no dia da posição' => '2026-07-31',
        'hoje no fuso de negócio' => '2026-08-09',
    };

    $submit = fn () => submitWithEvidence($review, BuilderReviewFixture::evidence($review, receivedOn: $receivedOn));

    if (in_array($case, ['antes da posição', 'amanhã'], true)) {
        expect($submit)->toThrow(
            SalesBoardBuilderReviewException::class,
            'A data de recebimento da resposta precisa estar entre 31/07/2026, data da posição, e 09/08/2026, hoje.',
        );

        expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft);

        return;
    }

    expect($submit()->builder_response_received_on->toDateString())->toBe($receivedOn);
})->with([
    'antes da posição',
    'amanhã',
    'no dia da posição',
    'hoje no fuso de negócio',
]);

it('refuses a blank respondent and an invalid e-mail', function () {
    $review = evidenceReadyReview();
    BuilderReviewFixture::useInMemoryEvidenceStore();

    expect(fn () => submitWithEvidence($review, BuilderReviewFixture::evidence($review, respondentName: '  ')))
        ->toThrow(SalesBoardBuilderReviewException::class, SalesBoardBuilderReviewException::builderRespondentRequired()->getMessage())
        ->and(fn () => submitWithEvidence($review, BuilderReviewFixture::evidence($review, respondentName: 'Al')))
        ->toThrow(SalesBoardBuilderReviewException::class, SalesBoardBuilderReviewException::builderRespondentRequired()->getMessage())
        ->and(fn () => submitWithEvidence($review, BuilderReviewFixture::evidence($review, respondentEmail: 'marina-arroba-construtora')))
        ->toThrow(SalesBoardBuilderReviewException::class, SalesBoardBuilderReviewException::builderRespondentEmailInvalid()->getMessage());

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and(SalesBoardBuilderReviewAttachment::query()->count())->toBe(0);
});

it('requires the general comment when the channel is other', function () {
    $review = evidenceReadyReview();
    BuilderReviewFixture::useInMemoryEvidenceStore();
    $evidence = BuilderReviewFixture::evidence($review, SalesBoardBuilderResponseChannel::Other);

    expect(fn () => submitWithEvidence($review, $evidence))
        ->toThrow(SalesBoardBuilderReviewException::class, SalesBoardBuilderReviewException::builderResponseChannelNeedsComment()->getMessage());

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft);

    $submitted = submitWithEvidence($review, $evidence, comment: 'Resposta enviada pelo aplicativo de mensagens do diretor comercial.');

    expect($submitted->builder_response_channel)->toBe(SalesBoardBuilderResponseChannel::Other)
        ->and($submitted->overall_comment)->toBe('Resposta enviada pelo aplicativo de mensagens do diretor comercial.');
});

it('refuses a file outside the allowed types and leaves nothing stored', function (string $case) {
    Storage::fake('local');
    $review = evidenceReadyReview();

    $file = match ($case) {
        'extensão html' => UploadedFile::fake()->createWithContent('resposta.html', '<html><body>Resposta</body></html>'),
        'MIME forjado' => UploadedFile::fake()->createWithContent('resposta.pdf', '<html><script>alert(1)</script></html>'),
    };

    expect(fn () => submitWithEvidence($review, BuilderReviewFixture::evidence($review, files: [BuilderReviewFixture::responseFile(), $file])))
        ->toThrow(SalesBoardBuilderReviewException::class, 'não foi aceito');

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and(SalesBoardBuilderReviewAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'extensão html',
    'MIME forjado',
]);

it('refuses more than five attachments', function () {
    Storage::fake('local');
    $review = evidenceReadyReview();

    $files = array_map(
        fn (int $number): UploadedFile => BuilderReviewFixture::responseFile("resposta-{$number}.pdf"),
        range(1, SalesBoardBuilderResponseEvidenceStore::maxFiles() + 1),
    );

    expect(fn () => submitWithEvidence($review, BuilderReviewFixture::evidence($review, files: $files)))
        ->toThrow(SalesBoardBuilderReviewException::class, SalesBoardBuilderReviewException::tooManyBuilderResponseAttachments(5)->getMessage());

    expect(Storage::disk('local')->allFiles())->toBe([]);

    // O par: cinco é o limite, e passa.
    $submitted = submitWithEvidence($review, BuilderReviewFixture::evidence($review, files: array_slice($files, 0, 5)));

    expect($submitted->attachments)->toHaveCount(5)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(5);
});

it('discards the stored response when the submission is refused inside the transaction', function () {
    Storage::fake('local');

    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    // Seções ainda pendentes: a recusa acontece dentro da transação, depois de
    // os arquivos terem sido gravados.
    expect(fn () => submitWithEvidence($review, BuilderReviewFixture::evidence($review, files: [
        BuilderReviewFixture::responseFile('resposta.pdf'),
        BuilderReviewFixture::responseFile('anexo.pdf'),
    ])))->toThrow(SalesBoardBuilderReviewException::class, 'Ainda falta validar');

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and(SalesBoardBuilderReviewAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('blocks an infected file and fails closed when the antivirus is unavailable', function (string $verdict) {
    Storage::fake('local');
    $review = evidenceReadyReview();

    bindEvidenceScanner(match ($verdict) {
        'infectado' => ClamAvFileScanner::RESULT_INFECTED,
        'antivírus indisponível' => ClamAvFileScanner::RESULT_UNAVAILABLE,
    });

    expect(fn () => submitWithEvidence($review, BuilderReviewFixture::evidence($review)))
        ->toThrow(SalesBoardBuilderReviewException::class, match ($verdict) {
            'infectado' => 'não passou na verificação de segurança e foi bloqueado',
            'antivírus indisponível' => SalesBoardBuilderReviewException::builderResponseScanUnavailable()->getMessage(),
        });

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    // O par: com o veredito limpo, a mesma resposta é gravada.
    bindEvidenceScanner(ClamAvFileScanner::RESULT_CLEAN);

    expect(submitWithEvidence($review, BuilderReviewFixture::evidence($review))->attachments->sole()->scan_status)
        ->toBe(MalwareScanStatus::Clean);
})->with([
    'infectado',
    'antivírus indisponível',
]);

it('keeps the attachment immutable', function () {
    $review = evidenceReadyReview();
    $submitted = BuilderReviewFixture::submit($review);
    $attachment = $submitted->attachments->sole();

    expect(fn () => $attachment->forceFill(['original_name' => 'trocado.pdf'])->save())
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $attachment->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');

    expect($attachment->fresh()->original_name)->toBe('resposta-construtora.pdf');
});

it('files the attachment and the response under the protected sales_board log', function () {
    $review = evidenceReadyReview();
    $submitted = BuilderReviewFixture::submit($review);

    $submission = Activity::query()
        ->where('subject_type', SalesBoardBuilderReview::class)
        ->where('subject_id', $submitted->id)
        ->get()
        ->first(fn (Activity $activity): bool => data_get($activity->attribute_changes, 'attributes.status') === SalesBoardBuilderReviewStatus::Submitted->value);

    $attachmentTrail = Activity::query()
        ->where('subject_type', SalesBoardBuilderReviewAttachment::class)
        ->where('subject_id', $submitted->attachments->sole()->id)
        ->sole();

    expect($submission)->not->toBeNull()
        ->and($submission->log_name)->toBe('sales_board')
        ->and(data_get($submission->attribute_changes, 'attributes.builder_respondent_name'))->toBe('Marina Ribeiro')
        ->and(data_get($submission->attribute_changes, 'attributes.builder_response_channel'))->toBe(SalesBoardBuilderResponseChannel::Email->value)
        ->and(array_key_exists('builder_respondent_email', (array) data_get($submission->attribute_changes, 'attributes')))->toBeFalse()
        ->and($attachmentTrail->log_name)->toBe('sales_board')
        ->and(data_get($attachmentTrail->attribute_changes, 'attributes.original_name'))->toBe('resposta-construtora.pdf')
        ->and(data_get($attachmentTrail->attribute_changes, 'attributes.checksum'))->not->toBeNull()
        ->and(config('audit.protected_logs'))->toContain('sales_board');
});

it('keeps the builder respondent as wide as the users table', function () {
    $review = evidenceReadyReview();
    BuilderReviewFixture::useInMemoryEvidenceStore();

    $domain = '@construtora.example';
    $name = mb_str_pad('Responsável Comercial da Construtora ', 255, 'ÃçÉ');
    $email = str_repeat('a', 255 - strlen($domain)).$domain;

    $actor = User::factory()->create();
    $actor->givePermissionTo(AccessPermission::SalesBoardsUpdate->value);

    $submitted = submitWithEvidence(
        $review,
        BuilderReviewFixture::evidence($review, respondentName: $name, respondentEmail: $email),
        $actor,
    )->fresh();

    expect(mb_strlen($name))->toBe(255)
        ->and(strlen($email))->toBe(255)
        ->and($submitted->builder_respondent_name)->toBe($name)
        ->and($submitted->builder_respondent_email)->toBe($email);
})->group('parity');
