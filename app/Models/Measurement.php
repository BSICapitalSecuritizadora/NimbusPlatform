<?php

namespace App\Models;

use App\Concerns\DerivesStoredFileMetadata;
use App\Enums\MeasurementReceiptReviewStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\DocumentStorageService;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementPlanVersionResolver;
use Database\Factories\MeasurementFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Measurement extends Model
{
    /** @use HasFactory<MeasurementFactory> */
    use DerivesStoredFileMetadata, HasFactory, LogsActivity;

    protected $attributes = [
        'storage_disk' => DocumentStorageService::DEFAULT_PRIVATE_DISK,
    ];

    public const STATUS_OPTIONS = [
        'pending' => 'Aguardando Análise',
        'in_review' => 'Em Análise',
        'paused' => 'Pausada',
        'rejected' => 'Recusada',
        'approved' => 'Documentação financeira completa',
        'awaiting_payment' => 'Etapa Pagamento',
        'awaiting_receipt' => 'Finalização — documentação pendente',
        'finalized' => 'Finalizada',
    ];

    /**
     * Medição que ainda pede trabalho de alguém.
     *
     * A distinção já existia espalhada -- My Pendings varria estes seis status,
     * o cockpit varria o complemento, o comando de SLA repetia a mesma lista --,
     * e agora o lifecycle da operação depende dela para decidir se pode
     * encerrar. Uma lista só, aqui, para que as quatro respostas não possam
     * divergir.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = [
        'pending',
        'in_review',
        'paused',
        'approved',
        'awaiting_payment',
        'awaiting_receipt',
    ];

    /**
     * O complemento exato de {@see self::OPEN_STATUSES}: recusada e finalizada
     * são os dois fins de linha do fluxo de medição.
     *
     * @var list<string>
     */
    public const CLOSED_STATUSES = [
        'rejected',
        'finalized',
    ];

    /**
     * O pagamento foi registrado para esta competência: movê-la levaria o
     * pagamento para outra sem justificativa nem aceite, e a competência paga
     * voltaria a ficar livre para uma nova medição.
     */
    public const PAID_COMPETENCE_CHANGE_REFUSAL = 'A competência de uma medição com pagamento registrado não pode ser alterada: o pagamento continua vinculado a ela.';

    /**
     * A competência decide a versão do plano, e a versão fica congelada no
     * arquivo desde o envio: a competência só muda para outra que a versão
     * congelada também rege. Argumentos: competência pedida (m/Y), versão
     * congelada, plano.
     */
    public const FROZEN_VERSION_COMPETENCE_CHANGE_REFUSAL = 'A competência %1$s não é regida pela %2$s do plano de %3$s, sob a qual esta medição foi enviada: a competência só muda dentro da vigência dessa versão. Para medir %1$s, recuse esta medição e envie uma nova.';

    /**
     * A competência é uma só, e a de cada arquivo é a da medição prevista
     * dele: mudá-la sem trocar as linhas deixaria a medição com duas
     * competências. Argumentos: plano, mês da linha (m/Y), competência pedida
     * (m/Y).
     */
    public const COMPETENCE_WITHOUT_ITS_LINES_REFUSAL = 'A medição prevista de %1$s é de %2$s: a competência só muda para %3$s junto com a medição prevista de cada empreendimento. Escolha a medição prevista de %3$s em cada arquivo.';

    /**
     * O Editar não acrescenta arquivo: a competência nova (ou a remoção de um
     * arquivo) não pode deixar de fora um empreendimento que a Engenharia vai
     * exigir nela. Argumentos: competência (m/Y), empreendimentos que faltam.
     */
    public const COMPETENCE_COVERAGE_REFUSAL = 'A competência %1$s exige também o arquivo de %2$s, e o Editar não acrescenta arquivo: para medir %1$s com esses empreendimentos, recuse esta medição e envie uma nova.';

    protected $fillable = [
        'operation_id',
        'reference_month',
        'filename',
        'storage_path',
        'storage_disk',
        'sha256',
        'file_size',
        'mime_type',
        'notes',
        'status',
        'current_stage',
        'uploaded_by',
        'uploaded_at',
        'analyzed_by',
        'analyzed_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $measurement): void {
            if ($measurement->exists && $measurement->isDirty('operation_id')) {
                throw new MeasurementWorkflowException('A operação de uma medição não pode ser alterada após sua criação.', [
                    'measurement_id' => $measurement->getKey(),
                    'original_operation_id' => $measurement->getOriginal('operation_id'),
                    'attempted_operation_id' => $measurement->operation_id,
                ]);
            }

            if ($measurement->exists
                && $measurement->hasApprovedEngineering()
                && $measurement->isDirty([
                    'reference_month',
                    'filename',
                    'storage_path',
                    'storage_disk',
                    'sha256',
                    'file_size',
                    'mime_type',
                    'engineering_snapshot',
                ])) {
                throw new MeasurementWorkflowException('Os dados aprovados pela Engenharia estão bloqueados. Devolva a medição à Engenharia para corrigi-los.', [
                    'measurement_id' => $measurement->getKey(),
                ]);
            }

            if ($measurement->exists
                && $measurement->isDirty('reference_month')
                && $measurement->payments()->exists()) {
                throw new MeasurementWorkflowException(self::PAID_COMPETENCE_CHANGE_REFUSAL, [
                    'measurement_id' => $measurement->getKey(),
                    'original_reference_month' => $measurement->getRawOriginal('reference_month'),
                ]);
            }

            if ($measurement->exists
                && $measurement->isDirty('reference_month')
                && $measurement->reference_month !== null) {
                $measurement->assertFilesFollowTheCompetence($measurement->reference_month);
            }

            if (blank($measurement->filename) && filled($measurement->storage_path)) {
                $measurement->filename = basename((string) $measurement->storage_path);
            }
        });

        // A recusa terminal solta as medições previstas que a medição ocupava
        // (`measurement_assets.line_claim_key`): a competência volta a aceitar
        // uma medição nova, e os arquivos continuam gravados como histórico.
        // Fica aqui, e não só no fluxo, para valer a qualquer gravação da
        // situação. Pela chave primária, para o UPDATE não travar o intervalo
        // do índice de `measurement_id` das outras medições.
        static::updated(function (self $measurement): void {
            if (! $measurement->wasChanged('status') || $measurement->holdsPlanLineClaims()) {
                return;
            }

            $claimingAssetIds = $measurement->assets()->whereNotNull('line_claim_key')->pluck('id')->all();

            if ($claimingAssetIds !== []) {
                MeasurementAsset::query()->whereKey($claimingAssetIds)->update(['line_claim_key' => null]);
            }
        });

        static::deleting(function (self $measurement): void {
            if ($measurement->hasWorkflowHistory()) {
                throw new MeasurementWorkflowException('Uma medição que já entrou no fluxo de análise não pode ser excluída: ela é encerrada pelo próprio fluxo.', [
                    'measurement_id' => $measurement->getKey(),
                    'status' => $measurement->status,
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'current_stage' => 'integer',
            'workflow_revision' => 'integer',
            'engineering_snapshot' => 'array',
            'file_size' => 'integer',
            'uploaded_at' => 'datetime',
            'analyzed_at' => 'datetime',
        ];
    }

    /**
     * A trilha de atributos da medição é evidência regulada, e a política de
     * retenção separa os baldes por `log_name`: `measurements` já era categoria
     * protegida por sete anos no `audit:clean-filtered`, mas ninguém escrevia
     * nela -- as mudanças de situação e de etapa caíam em `default` e seriam
     * descartadas em um ano.
     *
     * Isto não substitui o `measurement_workflow`: aquele registra a decisão
     * (quem aprovou qual etapa, por qual autoridade), este registra a mudança de
     * coluna. As duas trilhas são complementares e agora têm a mesma retenção.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('measurements')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? $this->status;
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function analyzedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MeasurementAsset::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MeasurementReview::class);
    }

    public function pauses(): HasMany
    {
        return $this->hasMany(MeasurementPause::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MeasurementPayment::class);
    }

    public function fileMigrationJournal(): MorphOne
    {
        return $this->morphOne(MeasurementFileMigration::class, 'migratable');
    }

    public function reviewForStage(int $stage): ?MeasurementReview
    {
        return $this->reviews->firstWhere('stage', $stage);
    }

    public function hasApprovedEngineering(): bool
    {
        return $this->reviews()
            ->where('stage', 1)
            ->where('status', 'approved')
            ->exists();
    }

    /**
     * A medição já entrou no fluxo de análise?
     *
     * Finalizada, ou com qualquer análise registrada -- pendente, pausada,
     * recusada ou aprovada, em qualquer etapa. A criação pela tela abre a
     * análise da Engenharia na mesma transação, então fica de fora só a medição
     * que nunca chegou a ser enviada: dado legado ou caminho programático.
     *
     * São as cláusulas de integridade de `MeasurementPolicy::delete()`,
     * repetidas aqui como invariante porque a policy não alcança todo caminho:
     * o super-admin passa pelo `Gate::before` sem consultá-la, e um `delete()`
     * Eloquent -- comando, tinker, ação recolocada na tela -- nem pergunta. Sem
     * o `deleting`, as análises, as pausas e os pagamentos desceriam pela FK em
     * cascata, sem trilha, inclusive o pagamento de uma medição devolvida da
     * Finalização à Engenharia, que já não tem a aprovação dela.
     */
    public function hasWorkflowHistory(): bool
    {
        return $this->status === 'finalized'
            || $this->reviews()->exists();
    }

    /**
     * A medição tem pagamento registrado?
     *
     * Pagamento não tem estorno nem exclusão no domínio: a partir dele a
     * competência, os arquivos e a obra e a linha de cada arquivo ficam presos
     * ao que foi pago, e a Engenharia passa a conferir só os empreendimentos
     * dos próprios arquivos.
     *
     * Serve à tela, que pergunta isso a cada campo travado: usa a relação já
     * carregada quando houver e, sem ela, consulta o banco. As guardas dos
     * models consultam o banco direto -- uma relação carregada antes do
     * pagamento não pode decidir a gravação.
     */
    public function hasRegisteredPayment(): bool
    {
        return $this->relationLoaded('payments')
            ? $this->payments->isNotEmpty()
            : $this->payments()->exists();
    }

    public function getResolvedStorageDiskAttribute(): string
    {
        return $this->storage_disk ?: 'public';
    }

    /**
     * A medição ocupa as medições previstas dos próprios arquivos? Toda
     * medição de pé ocupa -- aberta, finalizada, com Engenharia vigente ou com
     * pagamento --; só a recusada sem pagamento solta. É o mesmo predicado de
     * {@see MeasurementPlanLine::scopeAvailableForMeasurement()}.
     */
    public function holdsPlanLineClaims(): bool
    {
        return $this->status !== 'rejected'
            || $this->hasApprovedEngineering()
            || $this->payments()->exists();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * A competência gravada e os arquivos da medição contam a mesma história:
     * cada arquivo com medição prevista congelou no envio a versão do plano
     * que regia a competência ({@see MeasurementAsset::$plan_version_id}),
     * junto com a linha daquela competência. Depois de um Editar que mexa na
     * competência, nas linhas ou nos arquivos, a competência tem de ser regida
     * por todas essas versões e ser a de todas as linhas.
     *
     * @throws MeasurementWorkflowException
     */
    public function assertFilesFollowItsCompetence(): void
    {
        if ($this->reference_month instanceof DateTimeInterface) {
            $this->assertFilesFollowTheCompetence($this->reference_month);
        }
    }

    /**
     * Os arquivos cobrem os empreendimentos que a Engenharia vai exigir na
     * competência: os que a preveem pela versão que a regia no envio da
     * medição ({@see MeasurementEngineeringService}). O
     * Editar não acrescenta arquivo, então mudar a competência para um mês que
     * outro empreendimento prevê -- ou remover o arquivo de um exigido --
     * deixaria a medição sem aprovação possível. Com pagamento, a Engenharia
     * confere só os arquivos da própria medição, e nada aqui se aplica.
     *
     * @throws MeasurementWorkflowException
     */
    public function assertCoversItsCompetence(): void
    {
        if (! $this->reference_month instanceof DateTimeInterface || $this->payments()->exists()) {
            return;
        }

        $covered = $this->assets()->whereNotNull('plan_set_id')->pluck('plan_set_id')->map(fn (mixed $id): int => (int) $id)->all();
        $missing = MeasurementPlanLine::query()
            ->where('operation_id', $this->operation_id)
            ->governingTheirCompetence((int) $this->getKey())
            ->inCompetence($this->reference_month)
            ->whereNotIn('plan_set_id', $covered)
            ->distinct()
            ->orderBy('plan_set_id')
            ->pluck('plan_set_id');

        if ($missing->isEmpty()) {
            return;
        }

        $labels = MeasurementPlanSet::query()
            ->whereKey($missing->all())
            ->with('construction')
            ->orderBy('id')
            ->get()
            ->map(fn (MeasurementPlanSet $planSet): string => $planSet->construction?->development_name ?? $planSet->name)
            ->implode(', ');

        throw new MeasurementWorkflowException(sprintf(self::COMPETENCE_COVERAGE_REFUSAL, $this->reference_month->format('m/Y'), $labels), [
            'measurement_id' => $this->getKey(),
            'missing_plan_set_ids' => $missing->map(fn (mixed $id): int => (int) $id)->all(),
        ]);
    }

    /**
     * Cada arquivo com medição prevista congelou no envio a versão do plano
     * que regia a competência, junto com a linha dela. A competência tem de
     * ser regida por todas essas versões e ser a de todas as linhas: a medição
     * muda de competência trocando a linha de cada arquivo, dentro da vigência
     * da versão congelada. Lido do banco: no Editar, os arquivos já foram
     * gravados quando a medição grava a competência. O arquivo sem linha não
     * congelou versão.
     */
    private function assertFilesFollowTheCompetence(DateTimeInterface $competence): void
    {
        $resolver = app(MeasurementPlanVersionResolver::class);
        $assets = $this->assets()
            ->whereNotNull('plan_line_id')
            ->with(['planVersion', 'planLine', 'planSet.construction'])
            ->orderBy('id')
            ->get();
        $label = fn (MeasurementAsset $asset): string => $asset->planSet?->construction?->development_name ?? $asset->planSet?->name ?? 'o empreendimento';

        foreach ($assets as $asset) {
            if ($asset->planVersion !== null && ! $resolver->governs($asset->planVersion, $competence)) {
                throw new MeasurementWorkflowException(sprintf(
                    self::FROZEN_VERSION_COMPETENCE_CHANGE_REFUSAL,
                    $competence->format('m/Y'),
                    $asset->planVersion->label(),
                    $label($asset),
                ), [
                    'measurement_id' => $this->getKey(),
                    'asset_id' => $asset->getKey(),
                    'plan_version_id' => $asset->plan_version_id,
                ]);
            }
        }

        foreach ($assets as $asset) {
            $lineMonth = $asset->planLine?->measurement_date;

            if ($lineMonth !== null && $lineMonth->format('Y-m') !== $competence->format('Y-m')) {
                throw new MeasurementWorkflowException(sprintf(
                    self::COMPETENCE_WITHOUT_ITS_LINES_REFUSAL,
                    $label($asset),
                    $lineMonth->format('m/Y'),
                    $competence->format('m/Y'),
                ), [
                    'measurement_id' => $this->getKey(),
                    'asset_id' => $asset->getKey(),
                    'plan_line_id' => $asset->plan_line_id,
                ]);
            }
        }
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), self::OPEN_STATUSES);
    }

    /** @param Builder<self> $query */
    public function scopeWithPendingWork(Builder $query): Builder
    {
        return $query->where(fn (Builder $pending): Builder => $pending->open()
            ->orWhere(fn (Builder $corrections): Builder => $corrections
                ->where('status', 'finalized')
                ->where('current_stage', 5)
                ->whereHas('payments.currentReceiptEvidence', fn (Builder $evidences): Builder => $evidences
                    ->where('is_post_finalization', true)
                    ->whereIn('review_status', [MeasurementReceiptReviewStatus::Pending->value, MeasurementReceiptReviewStatus::Rejected->value]))));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('operation', fn (Builder $operations): Builder => $operations->visibleTo($user));
    }

    protected function storedFilePathColumn(): string
    {
        return 'storage_path';
    }

    protected function storedFileMimeColumn(): string
    {
        return 'mime_type';
    }

    protected function storedFileSizeColumn(): string
    {
        return 'file_size';
    }

    protected function storedFileChecksumColumn(): ?string
    {
        return 'sha256';
    }

    protected function storedFileNameColumn(): ?string
    {
        return 'filename';
    }

    protected function storedFileMetadataDisk(): string
    {
        return $this->resolved_storage_disk;
    }
}
