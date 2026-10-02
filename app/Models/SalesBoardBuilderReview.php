<?php

namespace App\Models;

use App\Enums\SalesBoardBuilderResponseChannel;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use Database\Factories\SalesBoardBuilderReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A validação da construtora sobre uma versão congelada.
 *
 * Enquanto é rascunho, é formulário: a construtora confirma seções, registra
 * divergências, muda de ideia. Depois de enviada, vira declaração -- e
 * declaração não se reescreve. Corrigir é abrir a tentativa seguinte, o que
 * preserva o que foi afirmado antes.
 *
 * A única mudança admitida depois do envio é ser marcada como substituída,
 * quando uma nova versão material do quadro faz o que ela revisou deixar de
 * existir. Isso não altera nada do que a construtora declarou: apenas registra
 * que aquela declaração se refere a um quadro que não é mais o vigente.
 *
 * Enquanto a construtora não tem canal próprio, a validação é registrada por
 * um operador interno, e o envio carrega a resposta dela: quem respondeu pela
 * construtora, o canal, a data do recebimento e os arquivos
 * ({@see SalesBoardBuilderReviewAttachment}). Rodadas enviadas antes dessa
 * exigência ficam sem resposta anexada, e a tela as reconhece por isso.
 */
class SalesBoardBuilderReview extends Model
{
    /** @use HasFactory<SalesBoardBuilderReviewFactory> */
    use HasFactory, LogsActivity;

    /**
     * O que o rascunho ainda pode gravar.
     *
     * @var list<string>
     */
    public const DRAFT_MUTABLE_FIELDS = [
        'status',
        'overall_comment',
        'submitted_at',
        'submitted_by_user_id',
        'reviewer_type',
        'reviewer_key',
        'reviewer_name',
        'reviewer_email',
        'builder_respondent_name',
        'builder_respondent_email',
        'builder_response_channel',
        'builder_response_received_on',
        'declaration_version',
        'superseded_at',
        'superseded_reason',
        'updated_at',
    ];

    /**
     * O que uma revisão já enviada ainda pode gravar: só a própria substituição.
     *
     * @var list<string>
     */
    public const SUPERSEDING_FIELDS = [
        'status',
        'superseded_at',
        'superseded_reason',
        'updated_at',
    ];

    protected $fillable = [
        'sales_board_cycle_id',
        'sales_board_cycle_baseline_id',
        'attempt',
        'status',
        'snapshot_fingerprint',
        'opened_at',
        'submitted_at',
        'superseded_at',
        'superseded_reason',
        'opened_by_user_id',
        'submitted_by_user_id',
        'reviewer_type',
        'reviewer_key',
        'reviewer_name',
        'reviewer_email',
        'builder_respondent_name',
        'builder_respondent_email',
        'builder_response_channel',
        'builder_response_received_on',
        'overall_comment',
        'declaration_version',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $review): void {
            $original = SalesBoardBuilderReviewStatus::tryFrom((string) $review->getRawOriginal('status'));
            $dirty = array_keys($review->getDirty());

            $allowed = match ($original) {
                SalesBoardBuilderReviewStatus::Draft => self::DRAFT_MUTABLE_FIELDS,
                SalesBoardBuilderReviewStatus::Submitted => self::SUPERSEDING_FIELDS,
                default => [],
            };

            if (array_diff($dirty, $allowed) !== []) {
                throw new LogicException('A submitted builder review is immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Builder reviews cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => SalesBoardBuilderReviewStatus::class,
            'opened_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'builder_response_channel' => SalesBoardBuilderResponseChannel::class,
            'builder_response_received_on' => 'immutable_date',
        ];
    }

    /**
     * Grava em `sales_board`, a categoria protegida do módulo. O envio fica
     * registrado com quem enviou e com a versão da declaração, e não só com a
     * data -- e com a resposta da construtora que o sustenta: quem respondeu,
     * por qual canal e quando. O e-mail de quem respondeu fica só na linha,
     * como o do revisor: é dado pessoal, e a trilha guarda o necessário para
     * localizar a resposta sem espalhá-lo por sete anos.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales_board')
            ->logOnly([
                'status',
                'attempt',
                'submitted_at',
                'submitted_by_user_id',
                'reviewer_type',
                'reviewer_name',
                'builder_respondent_name',
                'builder_response_channel',
                'builder_response_received_on',
                'declaration_version',
                'superseded_at',
                'superseded_reason',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycle::class, 'sales_board_cycle_id');
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleBaseline::class, 'sales_board_cycle_baseline_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * As seções na ordem em que a revisão as apresenta -- posição antes de
     * movimentação. Elas nascem juntas e nessa ordem, então o id a preserva.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(SalesBoardBuilderReviewSection::class, 'sales_board_builder_review_id')
            ->orderBy('id');
    }

    public function divergences(): HasMany
    {
        return $this->hasMany(SalesBoardBuilderDivergence::class, 'sales_board_builder_review_id');
    }

    /**
     * Os arquivos da resposta da construtora, na ordem em que foram anexados.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SalesBoardBuilderReviewAttachment::class, 'sales_board_builder_review_id')
            ->orderBy('id');
    }

    /**
     * A rodada carrega a resposta da construtora?
     *
     * Rodadas enviadas antes da exigência de evidência ficam com as colunas
     * nulas -- é assim que a tela as distingue, sem bloquear nada.
     */
    public function hasBuilderResponseEvidence(): bool
    {
        return filled($this->builder_respondent_name)
            && ($this->builder_response_channel !== null)
            && ($this->builder_response_received_on !== null);
    }

    /**
     * A devolução da Gestão que abriu esta rodada, se foi uma.
     *
     * A rodada criada por uma devolução é, por construção, a seguinte à rodada
     * devolvida e sobre o mesmo quadro. O critério usa só o que já existe: a
     * análise devolvida que analisou a rodada imediatamente anterior, com o
     * mesmo resumo de posição desta. Uma rodada aberta depois de um recálculo
     * material tem outro resumo, e o pedido da Gestão falava de um quadro que
     * não existe mais; um recálculo só de origem mantém o resumo, e o pedido
     * continua valendo.
     */
    public function originatingReturn(): ?SalesBoardManagementReview
    {
        if ((int) $this->attempt <= 1) {
            return null;
        }

        $previousRoundId = self::query()
            ->where('sales_board_cycle_id', $this->sales_board_cycle_id)
            ->where('attempt', '<', (int) $this->attempt)
            ->orderByDesc('attempt')
            ->value('id');

        if ($previousRoundId === null) {
            return null;
        }

        return SalesBoardManagementReview::query()
            ->where('sales_board_builder_review_id', $previousRoundId)
            ->where('status', SalesBoardManagementReviewStatus::Returned)
            ->where('snapshot_fingerprint', (string) $this->snapshot_fingerprint)
            ->orderByDesc('attempt')
            ->first();
    }

    public function isEditable(): bool
    {
        return $this->status === SalesBoardBuilderReviewStatus::Draft;
    }

    public function isSubmitted(): bool
    {
        return $this->status === SalesBoardBuilderReviewStatus::Submitted;
    }

    public function isSuperseded(): bool
    {
        return $this->status === SalesBoardBuilderReviewStatus::Superseded;
    }

    /**
     * A revisão ainda fala do quadro que a construtora viu?
     *
     * A comparação é pelo resumo da posição, não pelo id da versão: um recálculo
     * que muda apenas a origem material produz uma versão nova com exatamente o
     * mesmo quadro, e obrigar a construtora a revalidar isso seria pedir que ela
     * conferisse duas vezes a mesma coisa.
     */
    public function appliesTo(?SalesBoardCycleBaseline $baseline): bool
    {
        return ($baseline !== null)
            && ! $this->isSuperseded()
            && ((string) $this->snapshot_fingerprint === (string) $baseline->snapshot_fingerprint);
    }

    /**
     * Seções já respondidas, de um total de sete.
     *
     * @return array{resolved: int, total: int, percent: int}
     */
    public function progress(): array
    {
        $total = count(SectionEnum::ordered());
        $resolved = $this->sections
            ->filter(fn (SalesBoardBuilderReviewSection $section): bool => $section->status->isResolved())
            ->count();

        return [
            'resolved' => $resolved,
            'total' => $total,
            'percent' => $total === 0 ? 0 : (int) round(($resolved / $total) * 100),
        ];
    }

    public function isFullyConfirmed(): bool
    {
        return $this->sections->every(
            fn (SalesBoardBuilderReviewSection $section): bool => $section->status === SalesBoardBuilderReviewSectionStatus::Confirmed,
        );
    }

    public function attemptLabel(): string
    {
        return 'Tentativa '.$this->attempt;
    }
}
