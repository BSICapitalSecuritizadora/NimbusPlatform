<?php

namespace App\Models;

use App\Enums\MalwareScanStatus;
use App\Enums\SalesBoardSource;
use App\Observers\EmissionObserver;
use App\Services\SalesBoards\SalesBoardPositionReader;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\EmissionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[ObservedBy(EmissionObserver::class)]
class Emission extends Model
{
    /** @use HasFactory<EmissionFactory> */
    use HasFactory, LogsActivity;

    protected static function booted(): void
    {
        static::saving(function (self $emission): void {
            $emission->offer_type = 'CVM 160';

            if ($emission->isDirty(['remuneration_indexer', 'remuneration_rate'])) {
                $emission->remuneration = self::formatRemuneration(
                    $emission->remuneration_indexer,
                    $emission->remuneration_rate,
                );
            }
        });

        static::created(function (self $emission): void {
            if (filled($emission->bsi_code)) {
                return;
            }

            $emission->forceFill([
                'bsi_code' => self::generateBsiCode($emission),
            ])->saveQuietly();
        });
    }

    public static function defaultStorageDisk(): string
    {
        $defaultDisk = (string) config('filesystems.default', 'public');

        return $defaultDisk === 'local' ? 'public' : $defaultDisk;
    }

    public function getLogoStorageDiskAttribute(): string
    {
        return self::defaultStorageDisk();
    }

    public const TYPE_OPTIONS = [
        'CR' => 'CR',
        'CRA' => 'CRA',
        'CRI' => 'CRI',
    ];

    public const REMUNERATION_INDEXER_OPTIONS = [
        'CDI' => 'CDI',
        'IPCA' => 'IPCA',
        'Prefixado' => 'Prefixado',
    ];

    /** Status in which the emission is still being structured. */
    public const STATUS_DRAFT = 'draft';

    /**
     * "Liquidada": a operação foi encerrada.
     *
     * Não existe data de liquidação no cadastro -- o vencimento contratual
     * (`maturity_date`) não é a liquidação real, porque pré-pagamento e
     * repactuação mudam o fim --, e por isso o status é o fato operacional. É
     * também a trava de parada da automação do Quadro de Vendas: uma operação
     * encerrada não tem competência mensal a automatizar. O status é
     * reversível, e a automação volta sozinha se a liquidação for desfeita.
     */
    public const STATUS_LIQUIDATED = 'closed';

    public const STATUS_OPTIONS = [
        'draft' => 'Em Elaboração',
        'default' => 'Default',
        'active' => 'Em Distribuição',
        'closed' => 'Liquidada',
    ];

    public const ISSUER_SITUATION_OPTIONS = [
        'Recuperação Judicial' => 'Recuperação Judicial',
        'Inadimplente' => 'Inadimplente',
        'Adimplente' => 'Adimplente',
        'Falência' => 'Falência',
    ];

    /** Público alvo padrão das operações, preenchido automaticamente no cadastro. */
    public const DEFAULT_TARGET_AUDIENCE = 'Investidores Profissionais';

    /** Categoria do acervo que reúne os instrumentos jurídicos da operação. */
    public const GUARANTEE_SOURCE_DOCUMENT_CATEGORY = 'documentos_operacao';

    public const FORM_OPTIONS = [
        'Nominativa e escritural' => 'Nominativa e escritural',
        'Nominativa' => 'Nominativa',
        'Escritural' => 'Escritural',
        'Cartular' => 'Cartular',
    ];

    public const NEGOTIATIONS_SOURCE_LEGACY = 'legacy';

    public const NEGOTIATIONS_SOURCE_CONTRACTS = 'contracts';

    public const NEGOTIATIONS_SOURCE_OPTIONS = [
        self::NEGOTIATIONS_SOURCE_LEGACY => 'Manual (legado)',
        self::NEGOTIATIONS_SOURCE_CONTRACTS => 'Contratos (automático)',
    ];

    protected $fillable = [
        'name',
        'logo_path',
        'type',
        'if_code',
        'isin_code',
        'status',
        'issuer_situation',
        'bsi_code',
        'issuer',
        'lead_coordinator',
        'settlement_bank',
        'registrar',
        'distributor',
        'fiduciary_regime',
        'issue_date',
        'maturity_date',
        'monetary_update_period',
        'series',
        'emission_number',
        'issued_quantity',
        'monetary_update_months',
        'interest_payment_frequency',
        'offer_type',
        'concentration',
        'issued_price',
        'amortization_frequency',
        'integralized_quantity',
        'trustee_agent',
        'debtor',
        'law_firm',
        'remuneration_indexer',
        'remuneration_rate',
        'remuneration',
        'prepayment_possibility',
        'registered_with_cvm',
        'form_type',
        'segment',
        'target_audience',
        'issued_volume',
        'corporate_purpose',
        'subscription_and_integralization_terms',
        'amortization_payment_schedule',
        'remuneration_payment_schedule',
        'use_of_proceeds',
        'repactuation',
        'optional_early_redemption',
        'early_amortization',
        'remuneration_calculation',
        'guarantee_fund',
        'expense_fund',
        'reserve_fund',
        'works_fund',
        'fiduciary_assignment',
        'vehicle_fiduciary_alienation',
        'quota_fiduciary_alienation',
        'surety',
        'real_estate_guarantee',
        'property_fiduciary_alienation',
        'aval',
        'property_description',
        'segregated_estate',
        'guarantees_description',
        'covenants',
        'is_public',
        'negotiations_source',
        'description',
        'current_pu',
        'integralization_status',
    ];

    /**
     * O modo do Quadro de Vendas nasce legado também em memória.
     *
     * O default da coluna cobre a linha gravada; este cobre a instância recém
     * criada, antes de qualquer releitura. Sem ele o atributo é `null` até o
     * primeiro `refresh()`, e código que perguntasse o modo nesse intervalo
     * receberia "nenhum" em vez de "legado".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sales_board_source' => SalesBoardSource::Legacy->value,
        'sales_board_auto_open_builder_review' => false,
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'maturity_date' => 'date',
            'issued_price' => 'decimal:2',
            'issued_volume' => 'decimal:2',
            'remuneration_rate' => 'decimal:2',
            'prepayment_possibility' => 'boolean',
            'is_public' => 'boolean',
            'sales_board_source' => SalesBoardSource::class,
            'sales_board_automation_start_reference_month' => 'immutable_date',
            'sales_board_auto_open_builder_review' => 'boolean',
        ];
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? $this->status;
    }

    public function getFormattedRemunerationAttribute(): ?string
    {
        return self::formatRemuneration($this->remuneration_indexer, $this->remuneration_rate) ?? $this->remuneration;
    }

    public static function formatRemuneration(?string $indexer, string|float|int|null $rate): ?string
    {
        $normalizedIndexer = self::normalizeRemunerationIndexer($indexer);
        $hasRate = filled($rate);

        if (! filled($normalizedIndexer) && ! $hasRate) {
            return null;
        }

        if (filled($normalizedIndexer) && $hasRate) {
            return sprintf('%s + %s%% a.a.', $normalizedIndexer, number_format((float) $rate, 2, ',', '.'));
        }

        if (filled($normalizedIndexer)) {
            return $normalizedIndexer;
        }

        return sprintf('%s%% a.a.', number_format((float) $rate, 2, ',', '.'));
    }

    private static function normalizeRemunerationIndexer(?string $indexer): ?string
    {
        if (! filled($indexer)) {
            return null;
        }

        $trimmedIndexer = trim($indexer);

        foreach (array_keys(self::REMUNERATION_INDEXER_OPTIONS) as $option) {
            if (mb_strtolower($option) === mb_strtolower($trimmedIndexer)) {
                return $option;
            }
        }

        return $trimmedIndexer;
    }

    private static function generateBsiCode(self $emission): string
    {
        $referenceDate = $emission->created_at ?? now();

        return sprintf('BSI-%s-%04d', $referenceDate->format('Y'), $emission->getKey());
    }

    /**
     * A Emissão é o cadastro regulado do CRI e guarda o modo do Quadro de
     * Vendas. A trilha grava em `emissions`, categoria protegida, e não em
     * `default`, que é descartado em um ano.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('emissions')
            ->logOnlyDirty()
            ->logFillable()
            ->dontLogEmptyChanges();
    }

    public function investors(): BelongsToMany
    {
        return $this->belongsToMany(Investor::class, 'investor_emission')->withTimestamps();
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'emission_document')
            ->withPivot([
                'legal_document_type',
                'document_date',
                'signed_at',
                'amendment_order',
                'amends_document_id',
                'is_guarantee_source',
            ])
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function calculateIntegralizedQuantity(): int
    {
        return (int) round((float) $this->integralizationHistories()->sum('quantity'));
    }

    public function ensureIntegralizationQuantityWithinIssuedLimit(
        float|int|string|null $quantity,
        ?IntegralizationHistory $ignoringIntegralizationHistory = null,
    ): void {
        if (! filled($this->issued_quantity) && $this->issued_quantity !== 0 && $this->issued_quantity !== '0') {
            throw ValidationException::withMessages([
                'quantity' => 'Defina a Quantidade Emitida da emissão antes de registrar integralizações.',
            ]);
        }

        $requestedQuantity = (float) ($quantity ?? 0);
        $alreadyIntegralizedQuantity = $this->sumIntegralizationHistoriesQuantity($ignoringIntegralizationHistory);
        $availableQuantity = max(0, (float) $this->issued_quantity - $alreadyIntegralizedQuantity);

        if ($requestedQuantity <= ($availableQuantity + 0.0001)) {
            return;
        }

        throw ValidationException::withMessages([
            'quantity' => sprintf(
                'A quantidade informada excede a Quantidade Emitida. Restam %s disponíveis para integralização.',
                self::formatQuantityForValidationMessage($availableQuantity),
            ),
        ]);
    }

    public function syncIntegralizedQuantityFromHistories(): void
    {
        $this->forceFill([
            'integralized_quantity' => $this->calculateIntegralizedQuantity(),
        ])->saveQuietly();
    }

    public function isInDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * A operação foi encerrada ("Liquidada").
     *
     * A automação do Quadro de Vendas para aqui: nenhuma competência nova é
     * descoberta, os alvos abertos são encerrados e os lembretes param. Os atos
     * humanos continuam -- congelar e concluir a última competência, cancelar,
     * retornar ao legado.
     */
    public function isLiquidated(): bool
    {
        return $this->status === self::STATUS_LIQUIDATED;
    }

    public function usesContractNegotiations(): bool
    {
        return $this->negotiations_source === self::NEGOTIATIONS_SOURCE_CONTRACTS;
    }

    public function usesLegacyNegotiations(): bool
    {
        return ! $this->usesContractNegotiations();
    }

    public function getNegotiationsSourceLabelAttribute(): string
    {
        return self::NEGOTIATIONS_SOURCE_OPTIONS[$this->negotiations_source] ?? $this->negotiations_source ?? self::NEGOTIATIONS_SOURCE_OPTIONS[self::NEGOTIATIONS_SOURCE_LEGACY];
    }

    public function constructions(): HasMany
    {
        return $this->hasMany(Construction::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }

    /**
     * Units of every construction of the operation.
     *
     * @return HasManyThrough<ConstructionUnit, Construction, $this>
     */
    public function constructionUnits(): HasManyThrough
    {
        return $this->hasManyThrough(ConstructionUnit::class, Construction::class);
    }

    public function salesBoards(): HasMany
    {
        return $this->hasMany(SalesBoard::class);
    }

    /**
     * Qual workflow produz os próximos quadros mensais desta Emissão.
     *
     * Não confundir com "de onde a posição é lida": o
     * {@see SalesBoardPositionReader} continua
     * lendo `sales_boards` nos dois modos.
     */
    public function usesAutomatedSalesBoard(): bool
    {
        return $this->sales_board_source === SalesBoardSource::Automated;
    }

    /**
     * A competência a partir da qual a automação pode produzir quadros.
     *
     * Nulo nunca significa "desde sempre": sem competência de ativação a
     * Emissão não é elegível, e é isso que impede a automação de varrer o
     * histórico inteiro no dia em que for ligada.
     */
    public function automationStartsAt(): ?CarbonImmutable
    {
        $month = $this->sales_board_automation_start_reference_month;

        return $month === null
            ? null
            : CarbonImmutable::parse($month->toDateString())->startOfMonth();
    }

    /**
     * A competência informada já está coberta pela automação desta Emissão?
     */
    public function automationCovers(CarbonInterface $referenceMonth): bool
    {
        $start = $this->automationStartsAt();

        if (! $this->usesAutomatedSalesBoard() || $start === null) {
            return false;
        }

        return CarbonImmutable::parse($referenceMonth->toDateString())
            ->startOfMonth()
            ->greaterThanOrEqualTo($start);
    }

    public function salesBoardRolloutHomologations(): HasMany
    {
        return $this->hasMany(SalesBoardRolloutHomologation::class)->orderByDesc('attempt');
    }

    public function activeSalesBoardHomologation(): BelongsTo
    {
        return $this->belongsTo(SalesBoardRolloutHomologation::class, 'sales_board_active_homologation_id');
    }

    public function salesBoardRolloutRecipients(): HasMany
    {
        return $this->hasMany(SalesBoardRolloutRecipient::class);
    }

    public function salesBoardRolloutEvents(): HasMany
    {
        return $this->hasMany(SalesBoardRolloutEvent::class)->orderByDesc('id');
    }

    public function guarantees(): HasMany
    {
        return $this->hasMany(Guarantee::class);
    }

    public function guaranteeSnapshots(): HasMany
    {
        return $this->hasMany(GuaranteeSnapshot::class);
    }

    public function legalInstruments(): HasMany
    {
        return $this->hasMany(LegalInstrument::class);
    }

    /**
     * Todas as versões de campo dos instrumentos da emissão — é sobre elas que
     * a fila de revisão de alterações trabalha.
     */
    public function legalInstrumentFields(): HasManyThrough
    {
        return $this->hasManyThrough(LegalInstrumentField::class, LegalInstrument::class);
    }

    public function guaranteeMonthlyPositions(): HasMany
    {
        return $this->hasMany(GuaranteeMonthlyPosition::class);
    }

    public function extractedGuarantees(): HasMany
    {
        return $this->hasMany(ExtractedGuarantee::class);
    }

    public function guaranteeGenerationRuns(): HasMany
    {
        return $this->hasMany(GuaranteeGenerationRun::class);
    }

    public function latestGuaranteeGenerationRun(): HasOne
    {
        return $this->hasOne(GuaranteeGenerationRun::class)->latestOfMany();
    }

    /**
     * Documentos da emissão já classificados juridicamente, na ordem em que a
     * cadeia documental deve ser lida: do mais antigo para o mais recente.
     *
     * Documentos sem data ficam no fim em vez de no começo — sem data não há
     * como afirmar que precedem o Termo, e assumir que sim inverteria a
     * prioridade documental (§35).
     *
     * @return BelongsToMany<Document, $this>
     */
    /**
     * Documentos que a análise de garantias pode ler.
     *
     * Inclui tudo que está em "Documentos da Operação" mais qualquer documento
     * já classificado juridicamente. A classificação continua valendo — é ela
     * que ordena a cadeia documental e resolve prioridade entre instrumentos
     * (§35) —, mas não é pré-requisito para analisar: exigir que alguém
     * classificasse antes deixaria a operação sem nada a analisar no dia em que
     * os documentos foram anexados.
     *
     * Arquivos reprovados na varredura ficam de fora: não devem ser abertos nem
     * enviados a um processador externo.
     *
     * @return BelongsToMany<Document, $this>
     */
    public function guaranteeSourceDocuments(): BelongsToMany
    {
        return $this->documents()
            ->where(function ($query) {
                $query
                    ->where('documents.category', self::GUARANTEE_SOURCE_DOCUMENT_CATEGORY)
                    ->orWhereNotNull('emission_document.legal_document_type');
            })
            ->whereNotIn('documents.scan_status', [
                MalwareScanStatus::Infected->value,
                MalwareScanStatus::Rejected->value,
            ])
            ->orderByRaw('emission_document.document_date IS NULL')
            ->orderBy('emission_document.document_date')
            ->orderBy('emission_document.amendment_order')
            ->orderBy('documents.title');
    }

    public function legalDocuments(): BelongsToMany
    {
        return $this->documents()
            ->whereNotNull('emission_document.legal_document_type')
            ->orderByRaw('emission_document.document_date IS NULL')
            ->orderBy('emission_document.document_date')
            ->orderBy('emission_document.amendment_order');
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }

    public function puHistories(): HasMany
    {
        return $this->hasMany(PuHistory::class);
    }

    public function puParameter(): HasOne
    {
        return $this->hasOne(EmissionPuParameter::class);
    }

    public function puEvents(): HasMany
    {
        return $this->hasMany(EmissionPuEvent::class);
    }

    public function puDailyCurves(): HasMany
    {
        return $this->hasMany(EmissionPuDailyCurve::class);
    }

    /**
     * Linhas efetivamente operacionais: exclui candidate e preserva as legadas
     * sem `curve_version_id`. É esta a relação que alimenta leitura operacional.
     */
    public function operationalPuDailyCurves(): HasMany
    {
        return $this->hasMany(EmissionPuDailyCurve::class)->operational();
    }

    /**
     * Histórico administrativo completo: lista todos os papéis, inclusive
     * candidate. Não aplique filtro de role aqui -- há consumidores que precisam
     * do inventário inteiro.
     */
    public function puCurveVersions(): HasMany
    {
        return $this->hasMany(EmissionPuCurveVersion::class);
    }

    public function operationalPuCurveVersions(): HasMany
    {
        return $this->hasMany(EmissionPuCurveVersion::class)->operational();
    }

    public function candidatePuCurveVersions(): HasMany
    {
        return $this->hasMany(EmissionPuCurveVersion::class)->candidate();
    }

    /**
     * Dossiês de promoção operacional da curva. Governança, não leitura
     * operacional: quem quer a curva vigente usa `latestPuCurveVersion()`.
     */
    public function puCurvePromotions(): HasMany
    {
        return $this->hasMany(EmissionPuCurvePromotion::class);
    }

    public function puCalendarHomologations(): HasMany
    {
        return $this->hasMany(PuCalendarHomologation::class);
    }

    public function puBaselineEvidences(): HasMany
    {
        return $this->hasMany(EmissionPuBaselineEvidence::class);
    }

    public function currentPuCurveVersion(): ?EmissionPuCurveVersion
    {
        return $this->puCurveVersions()->current()->first();
    }

    /**
     * Última versão OPERACIONAL. Uma candidate com id/calculation_version maior
     * jamais pode vencer aqui: os consumidores desta relação leem "curva atual".
     */
    public function latestPuCurveVersion(): HasOne
    {
        return $this->hasOne(EmissionPuCurveVersion::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->operational(),
        );
    }

    public function latestCandidatePuCurveVersion(): HasOne
    {
        return $this->hasOne(EmissionPuCurveVersion::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->candidate(),
        );
    }

    public function integralizationHistories(): HasMany
    {
        return $this->hasMany(IntegralizationHistory::class);
    }

    public function extractedObligations(): HasMany
    {
        return $this->hasMany(ExtractedObligation::class);
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(Obligation::class);
    }

    public function obligationSeries(): HasMany
    {
        return $this->hasMany(ObligationSeries::class);
    }

    public function obligationEvidences(): HasManyThrough
    {
        return $this->hasManyThrough(ObligationEvidence::class, Obligation::class);
    }

    public function obligationGenerationRuns(): HasMany
    {
        return $this->hasMany(ObligationGenerationRun::class);
    }

    public function latestObligationGenerationRun(): HasOne
    {
        return $this->hasOne(ObligationGenerationRun::class)->latestOfMany();
    }

    /**
     * A aba de garantias pede ação? Ver {@see self::pendingGuaranteeSnapshotReason()}.
     */
    public function requiresMonthlyGuaranteeSnapshotUpdate(?CarbonInterface $referenceDate = null): bool
    {
        return $this->pendingGuaranteeSnapshotReason($referenceDate) !== null;
    }

    /**
     * Por que a competência de garantias precisa de ação, ou `null` se nada
     * está pendente. Os motivos se acumulam, nesta ordem:
     *
     * 1. competência apurada antes de um Quadro de Vendas publicado depois --
     *    aberta ou fechada, o número gravado não é mais o que o motor apuraria;
     * 2. competência cujo saldo devedor mudou depois da apuração (curva de PU
     *    homologada ou invalidada, Histórico importado, verificação diária);
     * 3. competência reaberta e ainda não fechada de novo: o número que saiu em
     *    relatório foi desfeito e ainda não tem substituto;
     * 4. a competência que se espera consolidar -- o mês de negócio anterior
     *    (America/Sao_Paulo) -- ainda não existe, ou foi só atualizada. O quadro
     *    de um mês só existe no seguinte, e o fechamento é o ritual que congela
     *    o número: uma apuração aberta não é consolidação, e é só a fechada que
     *    o relatório e a gravação tratam como o número do mês.
     *
     * A emissão sem garantia cadastrada não tem competência a consolidar, e não
     * é cobrada pelo item 4: um aviso que nunca apaga ensina a ignorar os reais.
     *
     * `$referenceDate` é um instante; o mês é o do calendário de negócio, nunca
     * o mês UTC.
     */
    public function pendingGuaranteeSnapshotReason(?CarbonInterface $referenceDate = null): ?string
    {
        if (! self::hasGuaranteeSnapshotsTable()) {
            return null;
        }

        $expectedCompetence = GuaranteeSnapshot::previousBusinessMonth($referenceDate ?? now());

        /** @var Collection<int, GuaranteeSnapshot> $snapshots */
        $snapshots = $this->guaranteeSnapshots()
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull('sales_board_outdated_at')
                ->orWhereNotNull('outstanding_balance_outdated_at')
                ->orWhere(fn (Builder $reopened): Builder => $reopened->whereNotNull('reopened_at')->whereNull('closed_at'))
                ->orWhereDate('reference_month', $expectedCompetence))
            ->orderBy('reference_month')
            ->get(['id', 'reference_month', 'sales_board_outdated_at', 'outstanding_balance_outdated_at', 'closed_at', 'reopened_at']);

        $months = fn (callable $filter): array => $snapshots
            ->filter($filter)
            ->map(fn (GuaranteeSnapshot $snapshot): string => $snapshot->formatted_reference_month)
            ->values()
            ->all();

        $reasons = [];

        $salesBoardMonths = $months(fn (GuaranteeSnapshot $snapshot): bool => $snapshot->isSalesBoardOutdated());

        if ($salesBoardMonths !== []) {
            $reasons[] = sprintf(
                'Quadro de Vendas publicado depois da apuração de %s. Atualize a competência (ou reabra, se fechada).',
                implode(', ', $salesBoardMonths),
            );
        }

        $balanceMonths = $months(fn (GuaranteeSnapshot $snapshot): bool => $snapshot->isOutstandingBalanceOutdated());

        if ($balanceMonths !== []) {
            $reasons[] = sprintf(
                'Saldo devedor alterado depois da apuração de %s. Atualize a competência (ou reabra, se fechada).',
                implode(', ', $balanceMonths),
            );
        }

        $reopenedMonths = $months(fn (GuaranteeSnapshot $snapshot): bool => $snapshot->wasReopenedAndNotClosed());

        if ($reopenedMonths !== []) {
            $reasons[] = sprintf('Competência reaberta e ainda não fechada: %s.', implode(', ', $reopenedMonths));
        }

        $expectedReason = $this->expectedCompetenceReason(
            $expectedCompetence,
            $snapshots->first(fn (GuaranteeSnapshot $snapshot): bool => $snapshot->reference_month->toDateString() === $expectedCompetence),
        );

        if ($expectedReason !== null) {
            $reasons[] = $expectedReason;
        }

        return $reasons === [] ? null : implode(' ', $reasons);
    }

    /**
     * O mês de negócio anterior só conta como consolidado quando está fechado.
     *
     * Reaberto, ele já aparece entre as competências reabertas. E não há o que
     * consolidar -- o item 4 não cobra -- quando:
     *
     * - a Emissão não tem garantia cadastrada;
     * - a operação foi liquidada: ela saiu da rotina mensal, e cobrar um
     *   fechamento por mês de uma operação encerrada é o aviso que nunca apaga;
     * - todas as garantias já estavam encerradas no fim da competência --
     *   liberadas, encerradas ou substituídas. A data é o fim do mês, a mesma
     *   em que o motor apura a competência
     *   ({@see Guarantee::contributesToCoverageOn()}): a garantia liberada em
     *   setembro ainda cobra o fechamento de agosto.
     *
     * Garantia suspensa, pendente, inconsistente ou vencida sem renovação
     * continua cobrando: ela ainda é da operação, e a competência fechada é o
     * que registra a cobertura que ela deixou de dar.
     */
    private function expectedCompetenceReason(string $expectedCompetence, ?GuaranteeSnapshot $expected): ?string
    {
        if ($expected?->isClosed() || $expected?->wasReopenedAndNotClosed()) {
            return null;
        }

        if ($this->isLiquidated()) {
            return null;
        }

        if (! $this->hasOpenGuaranteeOn(CarbonImmutable::parse($expectedCompetence)->endOfMonth())) {
            return null;
        }

        $label = GuaranteeSnapshot::formatReferenceMonthForDisplay($expectedCompetence);

        return $expected === null
            ? sprintf('A competência %s ainda não foi consolidada.', $label)
            : sprintf('A competência %s foi atualizada, mas ainda não foi fechada.', $label);
    }

    /**
     * Alguma garantia da Emissão ainda estava em aberto na data: a situação
     * jurídica dela naquela data não era de encerrada (liberada, encerrada ou
     * substituída) e a liberação, se houve, ainda não tinha valido.
     */
    private function hasOpenGuaranteeOn(CarbonInterface $date): bool
    {
        return $this->guarantees()
            ->with('events')
            ->get()
            ->contains(fn (Guarantee $guarantee): bool => (! $guarantee->legalStatusAsOf($date)->isClosed())
                && (($guarantee->released_at === null) || $guarantee->released_at->gt($date)));
    }

    public static function hasGuaranteeSnapshotsTable(): bool
    {
        return Schema::hasTable('guarantee_snapshots');
    }

    private function sumIntegralizationHistoriesQuantity(?IntegralizationHistory $ignoringIntegralizationHistory = null): float
    {
        return (float) $this->integralizationHistories()
            ->when(
                $ignoringIntegralizationHistory?->exists,
                fn ($query) => $query->whereKeyNot($ignoringIntegralizationHistory->getKey()),
            )
            ->sum('quantity');
    }

    private static function formatQuantityForValidationMessage(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, ',', '.'), '0'), ',');
    }
}
