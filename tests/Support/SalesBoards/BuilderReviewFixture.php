<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\DTOs\SalesBoards\BuilderResponseEvidence;
use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardBuilderResponseChannel;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardMovementType;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardBuilderResponseEvidenceStore;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use App\Services\SalesBoards\SalesBoardBuilderReviewOpeningService;
use App\Services\SalesBoards\SalesBoardBuilderReviewSubmissionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * Monta ciclos já congelados e prontos para validação.
 *
 * Começa onde a {@see CycleFixture} termina: uma competência com os quatro
 * baldes ocupados e os três tipos de movimento, que é o mínimo para exercitar as
 * sete seções da revisão.
 */
final class BuilderReviewFixture
{
    /**
     * Um empreendimento com estoque, financiado, quitado, permutado, uma venda e
     * um distrato na competência.
     *
     * @return array{cycle: SalesBoardCycle, construction: Construction, units: array<string, ConstructionUnit>, contracts: array<string, Contract>}
     */
    public static function generatedCycle(): array
    {
        [$construction, $units] = CycleFixture::readyConstruction(5);

        $financed = DerivationFixture::contract($units[1], '2026-03-10', '900000.00');
        DerivationFixture::installment($financed, '001', '2026-04-10', '450000.00', '2026-04-09', '450000.00');
        DerivationFixture::installment($financed, '002', '2026-10-10', '450000.00');

        $settled = DerivationFixture::contract($units[2], '2026-02-10', '850000.00');
        DerivationFixture::installment($settled, '001', '2026-03-10', '425000.00', '2026-03-09', '425000.00');
        DerivationFixture::installment($settled, '002', '2026-06-10', '425000.00', '2026-07-10', '425000.00');

        ConstructionUnitExchange::factory()->create([
            'construction_unit_id' => $units[3]->id,
            'exchange_value' => '700000.00',
            'effective_from' => '2026-01-01',
        ]);

        $soldInMonth = DerivationFixture::contract($units[4], '2026-07-15', '480000.00');
        DerivationFixture::installment($soldInMonth, '001', '2026-08-15', '480000.00');

        $cancelled = DerivationFixture::contract(
            $units[0],
            '2026-07-02',
            '470000.00',
            cancellationDate: '2026-07-20',
            status: ContractStatus::Cancelled,
        );
        DerivationFixture::installment($cancelled, '001', '2026-08-02', '470000.00');

        $cycle = CycleFixture::generate($construction)->cycle;

        return [
            'cycle' => $cycle,
            'construction' => $construction,
            'units' => [
                'stock' => $units[0],
                'financed' => $units[1],
                'settled' => $units[2],
                'exchanged' => $units[3],
                'soldInMonth' => $units[4],
            ],
            'contracts' => [
                'financed' => $financed,
                'settled' => $settled,
                'soldInMonth' => $soldInMonth,
                'cancelled' => $cancelled,
            ],
        ];
    }

    /**
     * A mesma competência, com a venda do mês fechada abaixo do mínimo
     * autorizado.
     *
     * A tabela da unidade é 500.000 e a política permite 10%, o que põe o piso
     * em 450.000; a venda sai por 400.000. O veredito de não conformidade é
     * apurado e congelado pela geração -- este fixture só produz o fato.
     *
     * @return array{cycle: SalesBoardCycle, construction: Construction, units: array<string, ConstructionUnit>, contracts: array<string, Contract>}
     */
    public static function generatedCycleWithNonConformSale(): array
    {
        [$construction, $units] = CycleFixture::readyConstruction(5);

        $financed = DerivationFixture::contract($units[1], '2026-03-10', '900000.00');
        DerivationFixture::installment($financed, '001', '2026-04-10', '450000.00', '2026-04-09', '450000.00');
        DerivationFixture::installment($financed, '002', '2026-10-10', '450000.00');

        $settled = DerivationFixture::contract($units[2], '2026-02-10', '850000.00');
        DerivationFixture::installment($settled, '001', '2026-03-10', '425000.00', '2026-03-09', '425000.00');
        DerivationFixture::installment($settled, '002', '2026-06-10', '425000.00', '2026-07-10', '425000.00');

        ConstructionUnitExchange::factory()->create([
            'construction_unit_id' => $units[3]->id,
            'exchange_value' => '700000.00',
            'effective_from' => '2026-01-01',
        ]);

        $soldInMonth = DerivationFixture::contract($units[4], '2026-07-15', '400000.00');
        DerivationFixture::installment($soldInMonth, '001', '2026-08-15', '400000.00');

        $cycle = CycleFixture::generate($construction)->cycle;

        return [
            'cycle' => $cycle,
            'construction' => $construction,
            'units' => [
                'stock' => $units[0],
                'financed' => $units[1],
                'settled' => $units[2],
                'exchanged' => $units[3],
                'soldInMonth' => $units[4],
            ],
            'contracts' => [
                'financed' => $financed,
                'settled' => $settled,
                'soldInMonth' => $soldInMonth,
            ],
        ];
    }

    public static function open(SalesBoardCycle $cycle, ?User $actor = null): SalesBoardBuilderReview
    {
        return app(SalesBoardBuilderReviewOpeningService::class)->open($cycle->fresh(), $actor);
    }

    public static function section(SalesBoardBuilderReview $review, SectionEnum $section): SalesBoardBuilderReviewSection
    {
        return $review->sections()->where('section', $section)->sole();
    }

    /**
     * Quem responde a validação, na identidade que o editor e o envio
     * autorizam. Sem ator informado, responde alguém que opera a competência:
     * os serviços recusam quem não tem `sales-boards.update`.
     */
    public static function reviewer(?User $actor = null): BuilderReviewerIdentity
    {
        return BuilderReviewerIdentity::forInternalUser($actor ?? GovernanceFixture::operator());
    }

    public static function confirmAll(SalesBoardBuilderReview $review, ?BuilderReviewerIdentity $reviewer = null): void
    {
        $editor = app(SalesBoardBuilderReviewEditor::class);
        $reviewer ??= self::reviewer();

        foreach (SectionEnum::ordered() as $section) {
            $row = self::section($review, $section);

            if ($row->isDivergent()) {
                continue;
            }

            $editor->confirmSection($row, $reviewer);
        }
    }

    public static function declare(
        SalesBoardBuilderReview $review,
        SectionEnum $section,
        SalesBoardBuilderDivergenceInput $input,
        ?BuilderReviewerIdentity $reviewer = null,
    ): SalesBoardBuilderDivergence {
        return app(SalesBoardBuilderReviewEditor::class)
            ->addDivergence(self::section($review, $section), $reviewer ?? self::reviewer(), $input);
    }

    /**
     * Sem ator informado, envia alguém que opera a competência -- e que não é
     * da Gestão, para que a aprovação de outra pessoa não esbarre na
     * segregação.
     *
     * O envio interno exige a resposta da construtora: sem evidência informada,
     * vai a de {@see self::evidence()}. A gravação do arquivo fica com o
     * armazenamento em memória, a menos que o teste peça o real -- quem prova o
     * upload usa `Storage::fake()` e `$realStore`.
     */
    public static function submit(
        SalesBoardBuilderReview $review,
        ?User $actor = null,
        ?string $comment = null,
        ?BuilderResponseEvidence $evidence = null,
        bool $realStore = false,
    ): SalesBoardBuilderReview {
        $actor ??= GovernanceFixture::operator();
        $review = $review->fresh();

        if (! $realStore) {
            self::useInMemoryEvidenceStore();
        }

        return app(SalesBoardBuilderReviewSubmissionService::class)->submit(
            $review,
            BuilderReviewerIdentity::forInternalUser($actor),
            $comment,
            $evidence ?? self::evidence($review),
        );
    }

    /**
     * Liga o armazenamento em memória da resposta da construtora no container.
     */
    public static function useInMemoryEvidenceStore(): void
    {
        app()->instance(
            SalesBoardBuilderResponseEvidenceStore::class,
            app(InMemoryBuilderResponseEvidenceStore::class),
        );
    }

    /**
     * Uma resposta da construtora completa: quem respondeu, o canal, o
     * recebimento na data da posição e um PDF.
     *
     * @param  list<UploadedFile>|null  $files
     */
    public static function evidence(
        ?SalesBoardBuilderReview $review = null,
        SalesBoardBuilderResponseChannel $channel = SalesBoardBuilderResponseChannel::Email,
        ?string $receivedOn = null,
        ?array $files = null,
        string $respondentName = 'Marina Ribeiro',
        string $respondentEmail = 'marina.ribeiro@construtora.example',
    ): BuilderResponseEvidence {
        return new BuilderResponseEvidence(
            respondentName: $respondentName,
            respondentEmail: $respondentEmail,
            channel: $channel,
            receivedOn: CarbonImmutable::parse($receivedOn ?? self::positionDateOf($review)),
            files: $files ?? [self::responseFile()],
        );
    }

    /**
     * Os campos da resposta da construtora como o formulário de envio os
     * recebe -- é o que `callAction('submitReview')` precisa somar à
     * declaração.
     *
     * @return array<string, mixed>
     */
    public static function evidenceFormData(?SalesBoardBuilderReview $review = null): array
    {
        return [
            'builder_respondent_name' => 'Marina Ribeiro',
            'builder_respondent_email' => 'marina.ribeiro@construtora.example',
            'builder_response_channel' => SalesBoardBuilderResponseChannel::Email->value,
            'builder_response_received_on' => self::positionDateOf($review),
            'builder_response_attachments' => [self::responseFile()],
        ];
    }

    /**
     * Um PDF de verdade, pequeno: o MIME é derivado do conteúdo gravado, e um
     * arquivo vazio não seria aceito como resposta.
     */
    public static function responseFile(string $name = 'resposta-construtora.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n% Resposta da construtora\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
        );
    }

    /**
     * A data da posição da rodada -- o fim da competência --, ou a das
     * competências que estes cenários geram.
     */
    private static function positionDateOf(?SalesBoardBuilderReview $review): string
    {
        return $review?->cycle?->position_date?->toDateString() ?? '2026-07-31';
    }

    public static function lineFor(SalesBoardBuilderReview $review, ConstructionUnit $unit): SalesBoardCycleLine
    {
        return $review->baseline->lines()->where('construction_unit_id', $unit->id)->sole();
    }

    public static function movementFor(
        SalesBoardBuilderReview $review,
        SalesBoardMovementType $type,
        Contract $contract,
    ): SalesBoardCycleMovement {
        return $review->baseline->movements()
            ->where('movement_type', $type)
            ->where('contract_id', $contract->id)
            ->sole();
    }
}
