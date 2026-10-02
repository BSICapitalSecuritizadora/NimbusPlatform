<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\BuilderResponseEvidence;
use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\DTOs\SalesBoards\StagedBuilderResponseAttachment;
use App\Enums\BuilderReviewerType;
use App\Enums\MalwareScanStatus;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewAttachment;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Support\BusinessTime;
use App\Support\SalesBoards\SalesBoardAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Fecha a validação da construtora e entrega a competência à Gestão.
 *
 * O envio é o ponto em que o formulário vira declaração: a partir daqui nada do
 * que a construtora escreveu muda mais. Corrigir é abrir a tentativa seguinte --
 * o que preserva o que foi afirmado antes, que é justamente o que a Gestão
 * precisa analisar.
 *
 * Enviar **não** é aprovar. O ciclo passa a "em análise da Gestão" e para aí:
 * nenhuma não conformidade é criada, nenhum quadro é publicado, ninguém é
 * notificado. Essas decisões são da fase seguinte, e antecipá-las aqui faria a
 * submissão da construtora parecer um aval que ela não é.
 *
 * O envio interno -- o único que existe hoje -- carrega a resposta da
 * construtora ({@see BuilderResponseEvidence}): quem respondeu por ela, o
 * canal, a data do recebimento e de 1 a 5 arquivos. É ela que dá ao registro
 * o peso de "validação da construtora", e por isso a exigência mora aqui, e
 * não só na tela: uma chamada direta ao serviço passa pela mesma regra. A
 * identidade externa, que nenhum caminho produz ainda, fica dispensada -- a
 * resposta dela é o próprio envio.
 */
class SalesBoardBuilderReviewSubmissionService
{
    /**
     * Versão do texto que a construtora aceita ao enviar. Congelada junto com a
     * submissão para que, se o texto mudar, se saiba qual foi aceito.
     */
    public const DECLARATION_VERSION = '2026-10-v2';

    public const MINIMUM_RESPONDENT_NAME_LENGTH = 3;

    public const MAXIMUM_RESPONDENT_LENGTH = 255;

    public function __construct(
        private readonly SalesBoardStaleDetectionService $staleDetectionService,
        private readonly SalesBoardBuilderReviewApplicability $applicability,
        private readonly SalesBoardBuilderResponseEvidenceStore $evidenceStore,
    ) {}

    public function submit(
        SalesBoardBuilderReview $review,
        BuilderReviewerIdentity $reviewer,
        ?string $overallComment = null,
        ?BuilderResponseEvidence $evidence = null,
    ): SalesBoardBuilderReview {
        /**
         * Quem envia vem antes de tudo: uma identidade que não diz quem é não
         * envia, e quem não opera a competência não envia. As duas recusas
         * acontecem antes da conferência contra a fonte, para que nenhuma
         * derivação rode e nenhuma constatação seja gravada a pedido de quem
         * não pode enviar. A identidade é conferida primeiro para que a recusa
         * de domínio -- "não foi possível identificar quem está enviando" --
         * continue sendo a resposta a uma identidade vazia.
         */
        $this->assertReviewerIdentified($reviewer);

        SalesBoardAccess::authorizeBuilderReviewer($reviewer);

        /**
         * A conferência final contra a fonte acontece **fora** da transação,
         * pelo mesmo motivo da abertura: ela grava o que encontrou, e uma recusa
         * desfaria esse registro junto. Descobrir aqui -- e não na análise da
         * Gestão -- que a posição enviada já nasceu desatualizada é o que este
         * custo compra, uma vez por validação.
         *
         * O que precisa de lock é a transição, não a derivação: a corrida a
         * evitar é dois envios simultâneos, e essa é resolvida logo abaixo.
         */
        $this->refreshStaleMetadata($review);

        /**
         * A resposta da construtora é conferida e gravada no disco privado
         * antes da transação: a varredura e a cópia dos arquivos não podem
         * segurar os locks do ciclo. Dentro dela só nascem as linhas. Qualquer
         * recusa daqui em diante apaga o que foi gravado, e o sucesso apaga os
         * temporários do upload depois do commit.
         */
        $staged = [];

        if ($reviewer->type === BuilderReviewerType::InternalPreview) {
            $evidence = $this->assertBuilderResponse($evidence, $review, $overallComment);
            $staged = $this->evidenceStore->stage($evidence->files, (int) $review->sales_board_cycle_id);
        } else {
            $evidence = null;
        }

        try {
            $submitted = $this->transition($review, $reviewer, $overallComment, $evidence, $staged);
        } catch (Throwable $exception) {
            $this->evidenceStore->discard($staged);

            throw $exception;
        }

        $this->evidenceStore->deleteTemporaryUploads($staged);

        return $submitted;
    }

    /**
     * A transição de rascunho para enviada, com a resposta da construtora.
     *
     * @param  list<StagedBuilderResponseAttachment>  $staged
     */
    private function transition(
        SalesBoardBuilderReview $review,
        BuilderReviewerIdentity $reviewer,
        ?string $overallComment,
        ?BuilderResponseEvidence $evidence,
        array $staged,
    ): SalesBoardBuilderReview {
        return DB::transaction(function () use ($review, $reviewer, $overallComment, $evidence, $staged): SalesBoardBuilderReview {
            /**
             * Ciclo e revisão travados na mesma ordem em que a abertura e a
             * edição os tocam: o ciclo primeiro. Dois envios simultâneos
             * serializam aqui, e o segundo encontra a revisão já enviada em vez
             * de produzir uma segunda transição. Uma edição concorrente também:
             * ela espera o envio e encontra a revisão congelada, ou termina
             * antes e o envio confere o que ela gravou.
             *
             * As seções vêm travadas junto. O ciclo já serializa quem segue a
             * ordem; o lock delas é o que impede uma escrita fora dessa ordem
             * de mudar uma resposta entre a conferência e o envio.
             */
            $cycle = SalesBoardCycle::query()
                ->whereKey($review->sales_board_cycle_id)
                ->lockForUpdate()
                ->firstOrFail();

            $review = SalesBoardBuilderReview::query()
                ->whereKey($review->getKey())
                ->lockForUpdate()
                ->with(['sections' => fn (HasMany $query): HasMany => $query->lockForUpdate()])
                ->firstOrFail();

            if ($review->status === SalesBoardBuilderReviewStatus::Submitted) {
                throw SalesBoardBuilderReviewException::alreadySubmitted();
            }

            if (! $review->isEditable()) {
                throw SalesBoardBuilderReviewException::reviewNotEditable();
            }

            if ($cycle->status !== SalesBoardCycleStatus::BuilderReview) {
                throw SalesBoardBuilderReviewException::cycleNotReviewable($cycle->status);
            }

            $this->assertStillApplies($cycle, $review);
            $this->assertSectionsResolved($review);

            $now = CarbonImmutable::now();

            /**
             * As linhas dos anexos nascem enquanto a rodada ainda é rascunho,
             * na mesma transação que a congela: ou a validação é enviada com a
             * resposta inteira, ou não é enviada.
             */
            foreach ($staged as $attachment) {
                SalesBoardBuilderReviewAttachment::query()->create([
                    'sales_board_builder_review_id' => $review->getKey(),
                    'disk' => $attachment->disk,
                    'path' => $attachment->path,
                    'original_name' => $attachment->originalName,
                    'mime_type' => $attachment->mimeType,
                    'size_bytes' => $attachment->sizeBytes,
                    'checksum' => $attachment->checksum,
                    'scan_status' => MalwareScanStatus::Clean,
                    'uploaded_by_user_id' => $reviewer->internalUserId,
                ]);
            }

            $review->forceFill([
                'status' => SalesBoardBuilderReviewStatus::Submitted,
                'submitted_at' => $now,
                'submitted_by_user_id' => $reviewer->internalUserId,
                'reviewer_type' => $reviewer->type->value,
                'reviewer_key' => $reviewer->stableKey,
                'reviewer_name' => $reviewer->displayName,
                'reviewer_email' => $reviewer->email,
                'builder_respondent_name' => $evidence?->respondentName,
                'builder_respondent_email' => $evidence?->respondentEmail,
                'builder_response_channel' => $evidence?->channel,
                'builder_response_received_on' => $evidence?->receivedOn?->toDateString(),
                'declaration_version' => self::DECLARATION_VERSION,
                'overall_comment' => $this->normalizeComment($overallComment) ?? $review->overall_comment,
            ])->save();

            $cycle->forceFill(['status' => SalesBoardCycleStatus::ManagementReview])->save();

            return $review->refresh();
        });
    }

    /**
     * A resposta da construtora que o envio interno exige.
     *
     * Falha fechada: sem evidência, ou com qualquer campo fora da regra, o
     * envio é recusado antes de qualquer arquivo ser gravado. A data é dia de
     * negócio, entre a data da posição -- a construtora responde sobre um
     * quadro já fechado -- e hoje. O canal "outro" só é aceito descrito nas
     * observações gerais, as que o envio grava ou as que o rascunho já tinha.
     */
    private function assertBuilderResponse(
        ?BuilderResponseEvidence $evidence,
        SalesBoardBuilderReview $review,
        ?string $overallComment,
    ): BuilderResponseEvidence {
        if ($evidence === null) {
            throw SalesBoardBuilderReviewException::builderResponseEvidenceRequired();
        }

        $name = trim(strip_tags((string) $evidence->respondentName));

        if ((mb_strlen($name) < self::MINIMUM_RESPONDENT_NAME_LENGTH) || (mb_strlen($name) > self::MAXIMUM_RESPONDENT_LENGTH)) {
            throw SalesBoardBuilderReviewException::builderRespondentRequired();
        }

        $email = trim((string) $evidence->respondentEmail);

        /**
         * A mesma regra de e-mail do formulário (a `email` do Laravel), e não
         * `FILTER_VALIDATE_EMAIL`, que recusa endereço válido com acento ou
         * com a parte local longa -- a coluna aceita os 255 caracteres de
         * `users.email`.
         */
        if (($email === '')
            || (mb_strlen($email) > self::MAXIMUM_RESPONDENT_LENGTH)
            || Validator::make(['email' => $email], ['email' => ['email']])->fails()) {
            throw SalesBoardBuilderReviewException::builderRespondentEmailInvalid();
        }

        if ($evidence->channel === null) {
            throw SalesBoardBuilderReviewException::builderResponseChannelRequired();
        }

        $from = CarbonImmutable::parse($review->cycle->position_date->toDateString())->startOfDay();
        $until = CarbonImmutable::parse(BusinessTime::dateString())->startOfDay();
        $receivedOn = $evidence->receivedOn?->startOfDay();

        if (($receivedOn === null) || $receivedOn->lessThan($from) || $receivedOn->greaterThan($until)) {
            throw SalesBoardBuilderReviewException::builderResponseReceivedOutOfRange($from, $until);
        }

        if ($evidence->channel->requiresComment()
            && (($this->normalizeComment($overallComment) ?? $this->normalizeComment($review->overall_comment)) === null)) {
            throw SalesBoardBuilderReviewException::builderResponseChannelNeedsComment();
        }

        if ($evidence->files === []) {
            throw SalesBoardBuilderReviewException::builderResponseAttachmentRequired();
        }

        if (count($evidence->files) > SalesBoardBuilderResponseEvidenceStore::maxFiles()) {
            throw SalesBoardBuilderReviewException::tooManyBuilderResponseAttachments(SalesBoardBuilderResponseEvidenceStore::maxFiles());
        }

        return new BuilderResponseEvidence(
            respondentName: $name,
            respondentEmail: $email,
            channel: $evidence->channel,
            receivedOn: $receivedOn,
            files: $evidence->files,
        );
    }

    /**
     * Confronta a versão vigente com a fonte de agora, antes de travar nada.
     */
    private function refreshStaleMetadata(SalesBoardBuilderReview $review): void
    {
        $cycle = $review->cycle;

        if (($cycle === null) || ($cycle->current_baseline_id === null)) {
            throw SalesBoardBuilderReviewException::withoutCurrentBaseline();
        }

        if (! $review->isEditable()) {
            throw $review->isSubmitted()
                ? SalesBoardBuilderReviewException::alreadySubmitted()
                : SalesBoardBuilderReviewException::reviewNotEditable();
        }

        $this->staleDetectionService->check($cycle);
    }

    /**
     * A validação precisa continuar falando do quadro vigente.
     *
     * A tela pode estar aberta há uma hora. Se nesse intervalo alguém recalculou
     * a posição, a conferência é sobre números que não são mais os do Nimbus, e
     * deixá-la atravessar para a Gestão entregaria uma análise nascida errada.
     */
    private function assertStillApplies(SalesBoardCycle $cycle, SalesBoardBuilderReview $review): void
    {
        $baseline = SalesBoardCycleBaseline::query()->find($cycle->current_baseline_id);

        if (! $review->appliesTo($baseline)) {
            throw SalesBoardBuilderReviewException::baselineChanged();
        }

        $blocker = $this->applicability->baselineBlocker($baseline);

        if ($blocker !== null) {
            throw SalesBoardBuilderReviewException::baselineNotEligible($blocker);
        }
    }

    /**
     * As sete seções precisam ter recebido uma resposta, e uma seção marcada
     * como divergente precisa realmente ter divergência.
     *
     * A segunda checagem não é paranoia: o status da seção e as divergências são
     * duas linhas diferentes do banco, e uma remoção concorrente pode deixá-las
     * discordando. Enviar nesse estado entregaria à Gestão uma seção que diz
     * "tem problema" sem dizer qual.
     */
    private function assertSectionsResolved(SalesBoardBuilderReview $review): void
    {
        /**
         * Listadas na ordem em que aparecem na tela. Uma mensagem que enumera
         * seções em ordem arbitrária obriga quem lê a procurar cada uma.
         */
        $pending = $review->sections
            ->filter(fn (SalesBoardBuilderReviewSection $section): bool => ! $section->status->isResolved())
            ->map(fn (SalesBoardBuilderReviewSection $section) => $section->section)
            ->sortBy(fn (SectionEnum $section): int => array_search($section, SectionEnum::ordered(), true))
            ->values()
            ->all();

        if ($pending !== []) {
            throw SalesBoardBuilderReviewException::sectionsPending($pending);
        }

        foreach ($review->sections as $section) {
            if ($section->status !== SalesBoardBuilderReviewSectionStatus::Divergent) {
                continue;
            }

            if (! $section->divergences()->exists()) {
                throw SalesBoardBuilderReviewException::divergentSectionWithoutDivergence($section->section);
            }
        }
    }

    private function assertReviewerIdentified(BuilderReviewerIdentity $reviewer): void
    {
        if ((trim($reviewer->stableKey) === '') || (trim($reviewer->displayName) === '')) {
            throw SalesBoardBuilderReviewException::reviewerIdentityRequired();
        }
    }

    private function normalizeComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : mb_substr(strip_tags($comment), 0, 2000);
    }
}
