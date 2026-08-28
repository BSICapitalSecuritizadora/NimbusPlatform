<?php

declare(strict_types=1);

namespace App\Services\LegalInstruments;

use App\Enums\GuaranteeEvidenceLevel;
use App\Enums\LegalDocumentType;
use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentDocumentStatus;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentFieldValueType;
use App\Enums\LegalInstrumentType;
use App\Models\Document;
use App\Models\Emission;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use App\Models\LegalInstrumentField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AltoBellevueContractEvidenceBackfill
{
    public const IF_CODE = '26E0017614';

    public const ISIN_CODE = 'BRALBLCRI008';

    private const EFFECTIVE_DATE = '2026-05-08';

    private const TERM_DOCUMENT_CHECKSUM = '23b47944933550386a51acefc05c245a6817d033876f096851fe9690c487ce2e';

    private const FIRST_AMENDMENT_DOCUMENT_CHECKSUM = 'a857112a830c5fac6e7dd2b39420e47fe5efafccb1d76efdd8e644868e4e60b3';

    /**
     * @return array{
     *     dry_run: bool,
     *     emission_id: int,
     *     instrument_id: int|null,
     *     document_id: int,
     *     infrastructure_actions: list<string>,
     *     rows: list<array<string, mixed>>,
     *     inserts: int,
     *     preserved: int,
     *     manual_review_required: list<array<string, mixed>>
     * }
     */
    public function execute(bool $write = false): array
    {
        $emission = $this->resolveEmission();
        $document = $this->resolveSecuritizationTermDocument($emission);

        if (! $write) {
            return $this->structure($emission, $document, false);
        }

        return DB::transaction(fn (): array => $this->structure($emission, $document, true));
    }

    /**
     * @return array{
     *     dry_run: bool,
     *     emission_id: int,
     *     instrument_id: int|null,
     *     document_id: int,
     *     infrastructure_actions: list<string>,
     *     rows: list<array<string, mixed>>,
     *     inserts: int,
     *     preserved: int,
     *     manual_review_required: list<array<string, mixed>>
     * }
     */
    private function structure(Emission $emission, Document $document, bool $write): array
    {
        $infrastructureActions = [];
        $instrument = $this->resolveSecuritizationTermInstrument($emission, $document);

        if (! $instrument instanceof LegalInstrument) {
            $infrastructureActions[] = 'insert_securitization_term_instrument';

            if ($write) {
                $instrument = LegalInstrument::query()->create([
                    'emission_id' => $emission->id,
                    'type' => LegalInstrumentType::SecuritizationTerm,
                    'name' => 'Termo de Securitização — CRI Alto Bellevue',
                    'status' => LegalInstrument::STATUS_ACTIVE,
                    'description' => 'Instrumento governante dos termos financeiros dos CRI da emissão.',
                ]);
            }
        }

        $instrumentDocument = $instrument instanceof LegalInstrument
            ? $this->resolveInstrumentDocument($instrument, $document)
            : null;

        if (! $instrumentDocument instanceof LegalInstrumentDocument) {
            $infrastructureActions[] = 'link_securitization_term_document';

            if ($write && $instrument instanceof LegalInstrument) {
                $instrumentDocument = LegalInstrumentDocument::query()->create([
                    'legal_instrument_id' => $instrument->id,
                    'document_id' => $document->id,
                    'role' => LegalInstrumentDocumentRole::Original,
                    'document_date' => $document->pivot?->document_date,
                    'effect_summary' => 'Evidências contratuais estruturadas; revisão jurídica pendente.',
                    'processing_status' => LegalInstrumentDocumentStatus::NeedsReview,
                ]);
            }
        }

        $firstAmendment = $this->resolveFirstAmendmentDocument($emission);
        $firstAmendmentLink = $firstAmendment instanceof Document && $instrument instanceof LegalInstrument
            ? $this->resolveInstrumentDocument($instrument, $firstAmendment)
            : null;

        if ($firstAmendment instanceof Document
            && $instrument instanceof LegalInstrument
            && ! $firstAmendmentLink instanceof LegalInstrumentDocument) {
            $infrastructureActions[] = 'link_first_amendment_to_governing_instrument';

            if ($write) {
                $existingAmendmentLink = LegalInstrumentDocument::query()
                    ->where('document_id', $firstAmendment->id)
                    ->orderBy('id')
                    ->first();

                LegalInstrumentDocument::query()->create([
                    'legal_instrument_id' => $instrument->id,
                    'document_id' => $firstAmendment->id,
                    'role' => LegalInstrumentDocumentRole::Amendment,
                    'sequence' => 1,
                    'document_date' => $existingAmendmentLink?->document_date
                        ?? $firstAmendment->pivot?->document_date,
                    'effect_summary' => 'Inclui Agente de Liquidação, exclui Índice Substitutivo e ratifica as demais condições.',
                    'processing_status' => LegalInstrumentDocumentStatus::NeedsReview,
                ]);
            }
        }

        $rows = collect($this->fieldDefinitions())
            ->map(fn (array $definition): array => $this->structureField(
                $instrument,
                $instrumentDocument,
                $document,
                $definition,
                $write,
            ))
            ->values();

        return [
            'dry_run' => ! $write,
            'emission_id' => (int) $emission->id,
            'instrument_id' => $instrument?->id,
            'document_id' => (int) $document->id,
            'infrastructure_actions' => $infrastructureActions,
            'rows' => $rows->all(),
            'inserts' => $rows->where('action', 'insert_pending_review')->count(),
            'preserved' => $rows->whereIn('action', [
                'preserve_confirmed',
                'preserve_pending_review',
            ])->count(),
            'manual_review_required' => [
                ...$rows->where('action', 'manual_review_required')->values()->all(),
                ...$this->misclassifiedIssueDateCandidates($emission),
            ],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function structureField(
        ?LegalInstrument $instrument,
        ?LegalInstrumentDocument $instrumentDocument,
        Document $document,
        array $definition,
        bool $write,
    ): array {
        $key = $definition['field_key'];

        if (! $key instanceof LegalInstrumentFieldKey) {
            throw new RuntimeException('Definição de campo sem chave jurídica válida.');
        }

        $candidate = $this->candidate($instrument, $instrumentDocument, $document, $definition);
        $existingFields = $instrument instanceof LegalInstrument
            ? $instrument->fields()
                ->with(['instrument', 'instrumentDocument.document', 'document'])
                ->where('field_key', $key->value)
                ->orderByDesc('id')
                ->get()
            : collect();
        $equivalent = $existingFields
            ->first(fn (LegalInstrumentField $field): bool => $candidate->hasSameValueAs($field)
                && $this->hasGoverningSource($field));
        $differentPending = $existingFields
            ->first(fn (LegalInstrumentField $field): bool => $field->status === LegalInstrumentFieldStatus::PendingReview
                && ! $candidate->hasSameValueAs($field));

        $action = match (true) {
            $differentPending instanceof LegalInstrumentField => 'manual_review_required',
            $equivalent?->status === LegalInstrumentFieldStatus::Confirmed => 'preserve_confirmed',
            $equivalent?->status === LegalInstrumentFieldStatus::PendingReview => 'preserve_pending_review',
            $equivalent?->status === LegalInstrumentFieldStatus::Rejected => 'manual_review_required',
            default => 'insert_pending_review',
        };

        if ($write && $action === 'insert_pending_review') {
            if (! $instrument instanceof LegalInstrument || ! $instrumentDocument instanceof LegalInstrumentDocument) {
                throw new RuntimeException('O instrumento e o documento governante devem existir antes dos campos.');
            }

            $candidate->save();
        }

        $proposedStatus = match ($action) {
            'preserve_confirmed' => LegalInstrumentFieldStatus::Confirmed->value,
            'manual_review_required' => 'manual_review_required',
            default => LegalInstrumentFieldStatus::PendingReview->value,
        };

        return [
            'field_key' => $key->value,
            'canonical_value' => $this->canonicalValue($candidate),
            'value_type' => $candidate->value_type->value,
            'document_id' => (int) $document->id,
            'document' => (string) $document->title,
            'instrument_id' => $instrument?->id,
            'instrument' => $instrument?->display_name ?? LegalInstrumentType::SecuritizationTerm->label(),
            'page' => $candidate->page,
            'clause' => $candidate->clause,
            'excerpt' => $candidate->excerpt,
            'evidence_level' => $candidate->evidence_level->value,
            'confidence_score' => $candidate->confidence_score,
            'proposed_status' => $proposedStatus,
            'action' => $action,
            'existing_field_id' => $action === 'manual_review_required'
                ? ($differentPending?->id ?? $equivalent?->id)
                : $equivalent?->id,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function candidate(
        ?LegalInstrument $instrument,
        ?LegalInstrumentDocument $instrumentDocument,
        Document $document,
        array $definition,
    ): LegalInstrumentField {
        $key = $definition['field_key'];

        return new LegalInstrumentField([
            'legal_instrument_id' => $instrument?->id,
            'field_key' => $key,
            'value_type' => $key->valueType(),
            'value' => $definition['value'],
            'value_numeric' => $definition['value_numeric'] ?? null,
            'value_date' => $definition['value_date'] ?? null,
            'effective_date' => self::EFFECTIVE_DATE,
            'status' => LegalInstrumentFieldStatus::PendingReview,
            'evidence_level' => $definition['evidence_level'],
            'confidence_score' => $definition['confidence_score'],
            'legal_instrument_document_id' => $instrumentDocument?->id,
            'document_id' => $document->id,
            'clause' => $definition['clause'],
            'page' => $definition['page'],
            'excerpt' => $definition['excerpt'],
            'has_conflict' => false,
        ]);
    }

    private function hasGoverningSource(LegalInstrumentField $field): bool
    {
        $role = $field->instrumentDocument?->role;

        return $field->instrument?->type === LegalInstrumentType::SecuritizationTerm
            && ($role === LegalInstrumentDocumentRole::Original
                || $role === LegalInstrumentDocumentRole::Amendment);
    }

    private function canonicalValue(LegalInstrumentField $field): string|float
    {
        return match ($field->value_type) {
            LegalInstrumentFieldValueType::Date => (string) $field->value_date?->toDateString(),
            LegalInstrumentFieldValueType::Money,
            LegalInstrumentFieldValueType::Percentage,
            LegalInstrumentFieldValueType::Number => (float) $field->value_numeric,
            default => (string) $field->value,
        };
    }

    private function resolveEmission(): Emission
    {
        $emissions = Emission::query()
            ->where('if_code', self::IF_CODE)
            ->where('isin_code', self::ISIN_CODE)
            ->get();

        if ($emissions->count() !== 1) {
            throw new RuntimeException(sprintf(
                'Esperada uma única emissão Alto Bellevue por IF %s e ISIN %s; encontradas: %d.',
                self::IF_CODE,
                self::ISIN_CODE,
                $emissions->count(),
            ));
        }

        return $emissions->sole();
    }

    private function resolveSecuritizationTermDocument(Emission $emission): Document
    {
        $documents = $emission->documents()
            ->wherePivot('legal_document_type', LegalDocumentType::SecuritizationTerm->value)
            ->get();

        if ($documents->isEmpty()) {
            $documents = $emission->documents()
                ->where('checksum', self::TERM_DOCUMENT_CHECKSUM)
                ->get()
                ->filter(fn (Document $document): bool => Str::contains(
                    Str::lower($document->title),
                    'termo de securitização',
                ));
        }

        if ($documents->count() !== 1) {
            throw new RuntimeException(sprintf(
                'Esperado um único Termo de Securitização classificado para a emissão %d; encontrados: %d.',
                $emission->id,
                $documents->count(),
            ));
        }

        return $documents->sole();
    }

    private function resolveFirstAmendmentDocument(Emission $emission): ?Document
    {
        $documents = $emission->documents()
            ->wherePivot('legal_document_type', LegalDocumentType::TermAmendment->value)
            ->get();

        if ($documents->isEmpty()) {
            $documents = $emission->documents()
                ->where('checksum', self::FIRST_AMENDMENT_DOCUMENT_CHECKSUM)
                ->get();
        }

        if ($documents->count() > 1) {
            throw new RuntimeException(sprintf(
                'Esperado no máximo um Primeiro Aditamento auditado para a emissão %d; encontrados: %d.',
                $emission->id,
                $documents->count(),
            ));
        }

        return $documents->first();
    }

    private function resolveSecuritizationTermInstrument(
        Emission $emission,
        Document $document,
    ): ?LegalInstrument {
        $instruments = $emission->legalInstruments()
            ->where('type', LegalInstrumentType::SecuritizationTerm->value)
            ->get();

        if ($instruments->count() <= 1) {
            return $instruments->first();
        }

        $linked = $instruments->filter(
            fn (LegalInstrument $instrument): bool => $instrument->documents()
                ->where('document_id', $document->id)
                ->exists(),
        );

        if ($linked->count() === 1) {
            return $linked->first();
        }

        throw new RuntimeException(sprintf(
            'A emissão %d possui %d Termos de Securitização e o documento %d não resolve o escopo de modo único.',
            $emission->id,
            $instruments->count(),
            $document->id,
        ));
    }

    private function resolveInstrumentDocument(
        LegalInstrument $instrument,
        Document $document,
    ): ?LegalInstrumentDocument {
        return $instrument->documents()
            ->where('document_id', $document->id)
            ->first();
    }

    /** @return list<array<string, mixed>> */
    private function misclassifiedIssueDateCandidates(Emission $emission): array
    {
        return $emission->legalInstrumentFields()
            ->with(['document', 'instrumentDocument.document', 'instrument'])
            ->where('field_key', LegalInstrumentFieldKey::IssueDate->value)
            ->where(function ($query): void {
                $query->whereDate('value_date', '2026-04-30')
                    ->orWhere('value', 'like', '%30/04/2026%')
                    ->orWhere('value', 'like', '%2026-04-30%');
            })
            ->get()
            ->filter(function (LegalInstrumentField $field): bool {
                $title = $field->document?->title
                    ?? $field->instrumentDocument?->document?->title
                    ?? '';

                return Str::contains(Str::lower($title), ['matrícula mãe', 'matricula mae']);
            })
            ->map(fn (LegalInstrumentField $field): array => [
                'field_key' => LegalInstrumentFieldKey::IssueDate->value,
                'canonical_value' => $field->value_date?->toDateString() ?? $field->value,
                'document_id' => $field->document?->id ?? $field->instrumentDocument?->document?->id,
                'document' => $field->document_label,
                'instrument_id' => $field->legal_instrument_id,
                'instrument' => $field->instrument?->display_name,
                'page' => $field->page,
                'clause' => $field->clause,
                'proposed_status' => 'manual_review_required',
                'action' => 'manual_review_required',
                'existing_field_id' => $field->id,
                'reason' => 'A data registral da Matrícula Mãe não comprova, por si só, a data de emissão dos CRI.',
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function fieldDefinitions(): array
    {
        $interestExcerpt = 'Os Juros Remuneratórios dos CRI serão devidos mensal e sucessivamente, de acordo com a tabela constante no Anexo II.';
        $scheduleExcerpt = '08/06/2026 Sim Não 0,0000% [...] 08/05/2031 Sim Sim 100,0000%.';
        $premiumExcerpt = 'Excepcionalmente no primeiro pagamento dos Juros Remuneratórios deverá ser capitalizado ao “Fator de Juros” um prêmio equivalente ao produtório de 2 (dois) Dias Úteis que antecedem a Data de Integralização. O cálculo ocorrerá de acordo com as regras do “Fator DI” e do “Fator Spread”.';

        return [
            $this->definition(LegalInstrumentFieldKey::IssueDate, '2026-05-08', '4.1.5', 23, 'A data de emissão dos CRI é o 08 de maio de 2026, para os CRI da 1ª Série.', valueDate: '2026-05-08'),
            $this->definition(LegalInstrumentFieldKey::MaturityDate, '2031-05-08', '4.1.6', 23, 'Os CRI 1ª Série terão o prazo de 1.826 dias corridos, vencendo-se em 08 de maio de 2031.', valueDate: '2031-05-08'),
            $this->definition(LegalInstrumentFieldKey::Indexer, 'CDI', '4.1.8', 24, 'Juros remuneratórios correspondentes à variação acumulada de 100% (cem por cento) da Taxa DI.', confidenceScore: 0.99),
            $this->definition(LegalInstrumentFieldKey::IndexPercentage, '1.0', '4.1.8', 24, 'Juros remuneratórios correspondentes à variação acumulada de 100% (cem por cento) da Taxa DI.', valueNumeric: 1.0),
            $this->definition(LegalInstrumentFieldKey::Spread, '0.06', '4.1.8', 24, 'Sobretaxa correspondente a 6,00% (seis inteiros por cento) ao ano referente aos CRI 1ª Série.', valueNumeric: 0.06),
            $this->definition(LegalInstrumentFieldKey::BusinessDayBasis, '252', '4.1.8', 24, 'Com base em um ano de 252 (duzentos e cinquenta e dois) Dias Úteis.', valueNumeric: 252.0),
            $this->definition(LegalInstrumentFieldKey::DayCountRule, 'business_days_start_inclusive_end_exclusive', '4.1.8', 25, 'Dup = número de Dias Úteis entre a Data de Integralização dos CRI, ou a última Data de Pagamento, inclusive, e a data de cálculo, exclusive, sendo “dup” um número inteiro.', evidenceLevel: GuaranteeEvidenceLevel::Inferred),
            $this->definition(LegalInstrumentFieldKey::BusinessDayDefinition, 'Qualquer dia que não seja sábado, domingo ou feriado nacional na República Federativa do Brasil; exclusivamente para obrigações não pecuniárias, também não pode ser feriado comercial no município de São Paulo, estado de São Paulo.', 'Definição “Dia(s) Útil(eis)”', 8, 'Qualquer dia que não seja sábado, domingo ou feriado nacional na República Federativa do Brasil, ou, ainda, exclusivamente no caso de obrigações não pecuniárias, que também não seja feriado comercial no município de São Paulo, estado de São Paulo.'),
            $this->definition(LegalInstrumentFieldKey::CalendarCode, 'BR_NATIONAL_HOLIDAYS', 'Definição “Dia(s) Útil(eis)”', 8, 'Qualquer dia que não seja sábado, domingo ou feriado nacional na República Federativa do Brasil.', evidenceLevel: GuaranteeEvidenceLevel::Inferred, confidenceScore: 0.90),
            $this->definition(LegalInstrumentFieldKey::IndexRateLookupMode, 'business_day_lag_exact', '4.1.8, observação (vi)', 26, 'Será sempre considerada a Taxa DI divulgada com 5 (cinco) Dias Úteis de defasagem; para cálculo no dia 14, a taxa considerada será a publicada no dia 7 pela B3.', evidenceLevel: GuaranteeEvidenceLevel::Inferred),
            $this->definition(LegalInstrumentFieldKey::IndexRateLagBusinessDays, '-5', '4.1.8, observação (vi)', 26, 'Será sempre considerada a Taxa DI divulgada com 5 (cinco) Dias Úteis de defasagem em relação à data efetiva de cálculo.', valueNumeric: -5.0),
            $this->definition(LegalInstrumentFieldKey::InitialUnitValue, '1000', '4.1.3', 23, 'Os CRI terão valor nominal unitário de R$ 1.000,00 (mil reais), na Data de Emissão.', valueNumeric: 1000.0),
            $this->definition(LegalInstrumentFieldKey::PaymentSchedule, 'monthly_interest_with_bullet_principal_at_maturity', 'Anexo II — Cronograma da 1ª Série', 111, $scheduleExcerpt, evidenceLevel: GuaranteeEvidenceLevel::Inferred),
            $this->definition(LegalInstrumentFieldKey::FirstInterestPaymentDate, '2026-06-08', 'Anexo II — Cronograma da 1ª Série', 111, '08/06/2026 Sim Não 0,0000%.', valueDate: '2026-06-08'),
            $this->definition(LegalInstrumentFieldKey::InterestPaymentFrequency, 'monthly', '4.1.8.1 e Anexo II', 26, $interestExcerpt),
            $this->definition(LegalInstrumentFieldKey::Amortization, 'bullet', 'Anexo II — Cronograma da 1ª Série', 112, $scheduleExcerpt, evidenceLevel: GuaranteeEvidenceLevel::Inferred),
            $this->definition(LegalInstrumentFieldKey::PaymentConvention, 'following_business_day', '4.1.22', 29, 'Considerar-se-ão automaticamente prorrogadas as datas de pagamento de qualquer obrigação relativa ao CRI até o primeiro Dia Útil subsequente.', evidenceLevel: GuaranteeEvidenceLevel::Inferred),
            $this->definition(LegalInstrumentFieldKey::FirstCouponPreIntegralizationPremiumEnabled, 'true', '4.1.8, observação (vii)', 26, $premiumExcerpt),
            $this->definition(LegalInstrumentFieldKey::FirstCouponPreIntegralizationBusinessDays, '2', '4.1.8, observação (vii)', 26, $premiumExcerpt, valueNumeric: 2.0),
            $this->definition(LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplyIndexFactor, 'true', '4.1.8, observação (vii)', 26, $premiumExcerpt),
            $this->definition(LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplySpreadFactor, 'true', '4.1.8, observação (vii)', 26, $premiumExcerpt),
        ];
    }

    /** @return array<string, mixed> */
    private function definition(
        LegalInstrumentFieldKey $fieldKey,
        string $value,
        string $clause,
        int $page,
        string $excerpt,
        ?float $valueNumeric = null,
        ?string $valueDate = null,
        GuaranteeEvidenceLevel $evidenceLevel = GuaranteeEvidenceLevel::Explicit,
        float $confidenceScore = 1.0,
    ): array {
        return [
            'field_key' => $fieldKey,
            'value' => $value,
            'value_numeric' => $valueNumeric,
            'value_date' => $valueDate,
            'clause' => $clause,
            'page' => $page,
            'excerpt' => $excerpt,
            'evidence_level' => $evidenceLevel,
            'confidence_score' => $confidenceScore,
        ];
    }
}
