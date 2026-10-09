<?php

namespace App\Models;

use App\Concerns\DerivesStoredFileMetadata;
use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\DocumentStorageService;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementPlanVersionResolver;
use App\Services\MeasurementRevisionService;
use Database\Factories\MeasurementFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Measurement extends Model
{
    /** @use HasFactory<MeasurementFactory> */
    use DerivesStoredFileMetadata, HasFactory, LogsActivity;

    /**
     * Os defaults do banco espelhados: a medição recém-criada, ainda não
     * relida, já é uma R0 vigente para os predicados que perguntam isso.
     */
    protected $attributes = [
        'storage_disk' => DocumentStorageService::DEFAULT_PRIVATE_DISK,
        'revision_number' => 0,
        'revision_status' => 'effective',
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
        'superseded' => 'Encerrada por revisão',
        'cancelled' => 'Revisão cancelada',
    ];

    /**
     * Colunas da identidade da revisão: gravadas pelo serviço de revisões na
     * criação e nunca mais alteradas -- a família, o número e a revisão
     * anterior não se reescrevem, e o motivo é o que a auditoria registrou.
     *
     * @var list<string>
     */
    public const REVISION_IDENTITY_COLUMNS = [
        'revision_family_id',
        'revision_root_id',
        'revision_number',
        'previous_revision_id',
        'previous_revision_number',
        'revision_reason',
        'revision_created_by',
        'previous_snapshot_sha256',
    ];

    /**
     * O conteúdo que a pessoa envia e edita. Depois que a revisão é só
     * histórico -- substituída, recusada ou cancelada --, nada disso muda.
     *
     * @var list<string>
     */
    private const SUBMITTED_CONTENT_COLUMNS = [
        'operation_id',
        'reference_month',
        'filename',
        'storage_path',
        'storage_disk',
        'sha256',
        'file_size',
        'mime_type',
        'notes',
        'uploaded_by',
        'uploaded_at',
    ];

    public const CLOSED_REVISION_CONTENT_REFUSAL = 'Esta revisão da medição está encerrada (%s) e é só histórico: os dados e os arquivos dela não mudam mais.';

    public const REVISION_COMPETENCE_REFUSAL = 'A competência de uma revisão é a da medição original e não pode ser alterada: para medir outro mês, envie uma nova medição.';

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
     * são os fins de linha do fluxo de medição; 'superseded' encerra o fluxo
     * ainda aberto (na etapa Pagamento, sem pagamento) da revisão que outra
     * substituiu, e 'cancelled' é o rascunho de revisão que nunca foi enviado.
     *
     * @var list<string>
     */
    public const CLOSED_STATUSES = [
        'rejected',
        'finalized',
        'superseded',
        'cancelled',
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

            $measurement->guardRevisionIdentity();

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

        // A R0 é a raiz da própria família: o id só existe depois da inserção,
        // e o MySQL não aceita coluna gerada, default nem CHECK sobre a coluna
        // AUTO_INCREMENT. Pela chave primária e sem eventos -- a identidade da
        // revisão é registrada pela auditoria do serviço, não pela trilha de
        // colunas.
        static::created(function (self $measurement): void {
            if ($measurement->getAttribute('revision_family_id') !== null) {
                return;
            }

            DB::table($measurement->getTable())
                ->where('id', $measurement->getKey())
                ->whereNull('revision_family_id')
                ->update(['revision_family_id' => $measurement->getKey()]);

            $measurement->setAttribute('revision_family_id', (int) $measurement->getKey());
            $measurement->syncOriginalAttribute('revision_family_id');
        });

        // A recusa terminal solta as medições previstas que a medição ocupava
        // (`measurement_assets.line_claim_key`): a competência volta a aceitar
        // uma medição nova, e os arquivos continuam gravados como histórico.
        // Fica aqui, e não só no fluxo, para valer a qualquer gravação da
        // situação. Pela chave primária, para o UPDATE não travar o intervalo
        // do índice de `measurement_id` das outras medições.
        //
        // Só os encerramentos soltam: a transição de etapa de uma revisão que
        // acabou de virar vigente (com a ocupação recém-transferida) nunca
        // pode ser lida como recusa.
        static::updated(function (self $measurement): void {
            if (! $measurement->wasChanged('status')
                || ! in_array($measurement->status, ['rejected', 'cancelled'], true)
                || $measurement->holdsPlanLineClaims()) {
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

            // O número de uma revisão nunca volta a valer: a revisão, mesmo
            // cancelada, fica como histórico, e a R0 que já tem revisão não sai.
            if ($measurement->isRevision() || $measurement->hasRevisionSuccessors()) {
                throw new MeasurementWorkflowException('Revisões de medição não se excluem: a revisão, mesmo cancelada, e a medição que já foi revisada ficam como histórico.', [
                    'measurement_id' => $measurement->getKey(),
                    'revision_number' => $measurement->revision_number,
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
            'revision_family_id' => 'integer',
            'revision_root_id' => 'integer',
            'revision_number' => 'integer',
            'previous_revision_id' => 'integer',
            'previous_revision_number' => 'integer',
            'revision_status' => MeasurementRevisionStatus::class,
            'revision_created_by' => 'integer',
            'revision_effective_at' => 'datetime',
            'revision_superseded_at' => 'datetime',
            'revision_closed_at' => 'datetime',
            'revision_closed_by' => 'integer',
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

    /**
     * Todas as revisões da medição lógica, a própria inclusive (R0, R1, R2...).
     * Nunca use com lockForUpdate: travar por intervalo de um índice não único
     * leva gap lock; a família é travada pela chave primária
     * ({@see MeasurementRevisionService::lockFamilyWithOperation()}).
     */
    public function revisionFamily(): HasMany
    {
        return $this->hasMany(self::class, 'revision_family_id', 'revision_family_id');
    }

    public function revisionRoot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_root_id');
    }

    public function previousRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_revision_id');
    }

    /**
     * As revisões que partiram desta (a R1 da R0, a R2 da R1...).
     */
    public function revisionSuccessors(): HasMany
    {
        return $this->hasMany(self::class, 'previous_revision_id');
    }

    /**
     * A revisão desta medição que está em análise: enquanto ela existe, o fluxo
     * desta (aberto na etapa Pagamento) fica suspenso.
     */
    public function reviewingSuccessor(): HasOne
    {
        return $this->hasOne(self::class, 'previous_revision_id')
            ->where('revision_status', MeasurementRevisionStatus::UnderReview->value);
    }

    public function revisionCreator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revision_created_by');
    }

    public function revisionCloser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revision_closed_by');
    }

    public function revisionDifferences(): HasMany
    {
        return $this->hasMany(MeasurementRevisionDifference::class);
    }

    /**
     * A situação da revisão. Falha alto quando a coluna não foi carregada: uma
     * leitura com lista de colunas que a esquece leria "nula" e trataria a
     * revisão como outra coisa, em silêncio.
     */
    public function revisionStatus(): MeasurementRevisionStatus
    {
        $this->assertRevisionAttributesLoaded(['revision_status']);

        $status = $this->getAttribute('revision_status');

        return $status instanceof MeasurementRevisionStatus
            ? $status
            : MeasurementRevisionStatus::from((string) $status);
    }

    public function revisionNumber(): int
    {
        $this->assertRevisionAttributesLoaded(['revision_number']);

        return (int) $this->getAttribute('revision_number');
    }

    /**
     * R1, R2...: uma correção de uma medição anterior. A R0 é a original.
     */
    public function isRevision(): bool
    {
        return $this->revisionNumber() > 0;
    }

    public function revisionLabel(): string
    {
        return 'R'.$this->revisionNumber();
    }

    /**
     * O id da R0 da família -- a medição lógica.
     */
    public function familyRootId(): int
    {
        $this->assertRevisionAttributesLoaded(['revision_family_id']);

        return (int) ($this->getAttribute('revision_family_id') ?? $this->getKey());
    }

    /**
     * A medição cujo envio ancora as leituras "conforme o envio" do histórico
     * do plano: a original. A revisão herda o contexto congelado da R0 e não
     * enxerga versões ativadas depois dela.
     */
    public function asOfMeasurementId(): int
    {
        return $this->familyRootId();
    }

    public function isEffectiveRevision(): bool
    {
        return $this->revisionStatus() === MeasurementRevisionStatus::Effective;
    }

    public function isPendingRevision(): bool
    {
        return $this->revisionStatus()->isPending();
    }

    public function isDraftRevision(): bool
    {
        return $this->revisionStatus() === MeasurementRevisionStatus::Draft;
    }

    public function isClosedRevision(): bool
    {
        return $this->revisionStatus()->isClosed();
    }

    /**
     * A medição já tem revisão (de qualquer situação) que partiu dela?
     */
    public function hasRevisionSuccessors(): bool
    {
        return $this->exists && $this->revisionSuccessors()->exists();
    }

    /**
     * O fluxo desta medição está suspenso por uma revisão em análise? Só a
     * revisão vigente parada na etapa Pagamento sem pagamento pode estar: é o
     * único estado aberto do qual se cria revisão (a finalizada não tem fluxo).
     * Usa a relação carregada quando houver; sem ela, consulta só as linhas
     * que podem estar suspensas, para as leituras em lote não multiplicarem
     * consultas.
     */
    public function isFrozenByRevision(): bool
    {
        if ($this->status !== 'awaiting_payment' || ! $this->isEffectiveRevision()) {
            return false;
        }

        if ($this->relationLoaded('reviewingSuccessor')) {
            return $this->reviewingSuccessor !== null;
        }

        return $this->reviewingSuccessor()->exists();
    }

    /**
     * Rótulo da situação do fluxo que considera a revisão: o rascunho nunca
     * foi enviado, e a medição suspensa espera a revisão.
     */
    public function workflowStatusLabel(): string
    {
        if ($this->isDraftRevision()) {
            return 'Rascunho da revisão '.$this->revisionLabel();
        }

        return self::STATUS_OPTIONS[$this->status] ?? (string) $this->status;
    }

    /**
     * A medição aceita edição dos dados e dos arquivos enviados? A original
     * segue as regras de sempre (a Engenharia aprovada, a finalização e o
     * pagamento travam por outras guardas). A revisão só é editada no rascunho
     * ou com o fluxo aberto antes da Engenharia -- nunca depois de encerrada,
     * nem pela via administrativa.
     */
    public function acceptsEdits(): bool
    {
        if ($this->revisionStatus() === MeasurementRevisionStatus::Superseded) {
            return false;
        }

        if (! $this->isRevision()) {
            return true;
        }

        return match ($this->revisionStatus()) {
            MeasurementRevisionStatus::Draft => $this->status === 'pending',
            MeasurementRevisionStatus::UnderReview, MeasurementRevisionStatus::Effective => in_array($this->status, ['pending', 'in_review', 'paused'], true),
            default => false,
        };
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertRevisionAttributesLoaded(array $columns): void
    {
        if (! $this->exists) {
            return;
        }

        foreach ($columns as $column) {
            if (! array_key_exists($column, $this->getAttributes())) {
                throw new LogicException("A coluna {$column} da medição #{$this->getKey()} não foi carregada: a leitura precisa selecioná-la para decidir sobre a revisão.");
            }
        }
    }

    /**
     * Identidade e situação da revisão: imutáveis depois de gravadas, com
     * transições só pela tabela do enum; a competência da revisão é a da
     * original; e a revisão encerrada não muda o que foi enviado. Vale para toda
     * gravação, inclusive a de quem passa pelo bypass administrativo das
     * policies.
     */
    private function guardRevisionIdentity(): void
    {
        if (! $this->exists) {
            return;
        }

        foreach (self::REVISION_IDENTITY_COLUMNS as $column) {
            if (! $this->isDirty($column)) {
                continue;
            }

            // A única escrita admitida é a da raiz que ficou sem família (linha
            // inserida sem o gancho `created`): a família da R0 é ela mesma.
            $healsTheRoot = $column === 'revision_family_id'
                && $this->getRawOriginal('revision_family_id') === null
                && (int) $this->getAttribute('revision_family_id') === (int) $this->getKey()
                && (int) $this->getRawOriginal('revision_number') === 0;

            if (! $healsTheRoot) {
                throw new MeasurementWorkflowException('A identidade de uma revisão de medição (família, número, revisão anterior e motivo) não pode ser alterada.', [
                    'measurement_id' => $this->getKey(),
                    'column' => $column,
                ]);
            }
        }

        // Os marcos da revisão são gravados uma vez, por quem faz a transição.
        foreach (['revision_effective_at', 'revision_superseded_at', 'revision_closed_at', 'revision_closed_reason'] as $milestone) {
            if ($this->isDirty($milestone) && $this->getRawOriginal($milestone) !== null) {
                throw new MeasurementWorkflowException('Os marcos de uma revisão de medição (vigência, substituição e encerramento) não podem ser reescritos.', [
                    'measurement_id' => $this->getKey(),
                    'column' => $milestone,
                ]);
            }
        }

        if ($this->isDirty('revision_status')) {
            $from = MeasurementRevisionStatus::tryFrom((string) $this->getRawOriginal('revision_status'));
            $to = $this->revisionStatus();

            if ($from !== null && ! $from->canTransitionTo($to)) {
                throw new MeasurementWorkflowException(sprintf(
                    'A revisão %s não pode passar de "%s" para "%s".',
                    $this->revisionLabel(),
                    $from->label(),
                    $to->label(),
                ), [
                    'measurement_id' => $this->getKey(),
                    'from' => $from->value,
                    'to' => $to->value,
                ]);
            }
        }

        if ($this->isDirty('reference_month') && (int) $this->getRawOriginal('revision_number') > 0) {
            throw new MeasurementWorkflowException(self::REVISION_COMPETENCE_REFUSAL, [
                'measurement_id' => $this->getKey(),
            ]);
        }

        $originalStatus = MeasurementRevisionStatus::tryFrom((string) $this->getRawOriginal('revision_status'));

        if ($originalStatus !== null
            && $originalStatus->isClosed()
            && ((int) $this->getRawOriginal('revision_number') > 0 || $originalStatus === MeasurementRevisionStatus::Superseded)
            && $this->isDirty(self::SUBMITTED_CONTENT_COLUMNS)) {
            throw new MeasurementWorkflowException(sprintf(self::CLOSED_REVISION_CONTENT_REFUSAL, mb_strtolower($originalStatus->label())), [
                'measurement_id' => $this->getKey(),
                'revision_status' => $originalStatus->value,
            ]);
        }
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
     *
     * E só a revisão vigente ocupa: a revisão em rascunho ou em análise não
     * toma a linha da vigente (a ocupação passa de uma para a outra, numa
     * transação só, quando a revisão vira a vigente), e a substituída, a
     * recusada e a cancelada já não ocupam nada. A original é vigente desde o
     * envio, então para ela nada muda.
     */
    public function holdsPlanLineClaims(): bool
    {
        if (! $this->isEffectiveRevision()) {
            return false;
        }

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
        // A revisão cobre exatamente os empreendimentos da revisão que ela
        // substitui, herdados com o contexto congelado: quem confere é o
        // serviço de revisões, pela correspondência um a um dos arquivos.
        if (! $this->reference_month instanceof DateTimeInterface || $this->isRevision() || $this->payments()->exists()) {
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

    /**
     * Medição aberta que pede trabalho -- menos a suspensa por uma revisão em
     * análise, cujo fluxo ninguém pode mover enquanto a revisão corre -- e a
     * finalizada com correção de comprovante pendente (inclusive a substituída
     * por revisão: os pagamentos dela continuam sendo dela).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithPendingWork(Builder $query): Builder
    {
        $measurements = $query->getModel()->getTable();

        return $query->where(fn (Builder $pending): Builder => $pending
            ->where(fn (Builder $open): Builder => $open->open()
                ->whereNotExists(fn (QueryBuilder $successors): QueryBuilder => self::whereReviewingSuccessor($successors, $measurements)))
            ->orWhere(fn (Builder $corrections): Builder => $corrections
                ->where('status', 'finalized')
                ->where('current_stage', 5)
                ->whereHas('payments.currentReceiptEvidence', fn (Builder $evidences): Builder => $evidences
                    ->where('is_post_finalization', true)
                    ->whereIn('review_status', [MeasurementReceiptReviewStatus::Pending->value, MeasurementReceiptReviewStatus::Rejected->value]))));
    }

    /**
     * Subconsulta "existe revisão em análise que partiu desta medição" sobre a
     * tabela (ou apelido) `$measurements` -- pela FK da revisão anterior, que
     * nunca casa com a própria linha.
     */
    public static function whereReviewingSuccessor(QueryBuilder $query, string $measurements): QueryBuilder
    {
        return $query
            ->from('measurements as reviewing_successors')
            ->whereColumn('reviewing_successors.previous_revision_id', "{$measurements}.id")
            ->where('reviewing_successors.revision_status', MeasurementRevisionStatus::UnderReview->value);
    }

    /**
     * As revisões que representam a medição lógica hoje: a vigente e a que
     * está em rascunho ou em análise. Substituídas, recusadas e canceladas são
     * histórico -- continuam acessíveis pela família, mas não contam como
     * medições correntes.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrentRevisions(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('revision_status'), [
            MeasurementRevisionStatus::Superseded->value,
            MeasurementRevisionStatus::Rejected->value,
            MeasurementRevisionStatus::Cancelled->value,
        ]);
    }

    /**
     * Revisões (R1, R2...) cuja família já pagou antes delas: na etapa
     * Pagamento, elas podem ser aprovadas sem pagamento próprio -- o que falta
     * ou sobra é a posição financeira, conferida à parte.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithEarlierFamilyPayments(Builder $query): Builder
    {
        $measurements = $query->getModel()->getTable();

        return $query
            ->where($query->qualifyColumn('revision_number'), '>', 0)
            ->whereExists(fn (QueryBuilder $payments): QueryBuilder => $payments
                ->from('measurement_payments as earlier_payments')
                ->join('measurements as earlier_members', 'earlier_members.id', '=', 'earlier_payments.measurement_id')
                ->whereColumn('earlier_members.revision_family_id', "{$measurements}.revision_family_id")
                ->whereColumn('earlier_members.revision_number', '<', "{$measurements}.revision_number"));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEffectiveRevisions(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('revision_status'), MeasurementRevisionStatus::Effective->value);
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
