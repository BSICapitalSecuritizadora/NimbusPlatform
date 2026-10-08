<?php

namespace App\Models;

use App\Concerns\DerivesStoredFileMetadata;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\DocumentStorageService;
use App\Services\MeasurementFileValidationService;
use Database\Factories\MeasurementAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeasurementAsset extends Model
{
    /** @use HasFactory<MeasurementAssetFactory> */
    use DerivesStoredFileMetadata, HasFactory;

    /**
     * O pagamento é registrado sobre o contexto que a Engenharia aprovou a
     * partir destes arquivos, e a medição paga devolvida à Engenharia só sai
     * pela reaprovação -- que confere justamente os empreendimentos dos
     * arquivos dela (`MeasurementEngineeringService::validateAndRecord()`).
     * Remover um arquivo deixaria a medição sem saída: o Editar não acrescenta
     * arquivo, a recusa terminal não vale com pagamento e a Engenharia passaria
     * a conferir uma cobertura que o pagamento não tem.
     */
    public const PAID_FILE_REMOVAL_REFUSAL = 'O arquivo de uma medição com pagamento registrado não pode ser removido: o pagamento depende dele.';

    /**
     * Trocar a obra ou a linha do arquivo pago levaria o pagamento para outra
     * competência sem justificativa nem aceite, e liberaria a linha paga para
     * uma nova medição -- a mesma competência paga duas vezes. Trocar o arquivo
     * na mesma linha continua sendo a correção da Engenharia.
     */
    public const PAID_CONTEXT_CHANGE_REFUSAL = 'A obra e a linha do cronograma de uma medição com pagamento registrado não podem ser alteradas: o pagamento continua vinculado a elas.';

    /**
     * A medição nasce sob a versão vigente do plano. Entre abrir o formulário
     * e enviar, outra pessoa pode ter ativado uma revisão.
     */
    public const SUPERSEDED_PLAN_REFUSAL = 'A medição prevista escolhida não é mais da versão vigente do plano de %s: o plano foi revisado. Recarregue a página e escolha a medição prevista de novo.';

    /**
     * Plano que nunca foi ativado não recebe medição: o cronograma dele ainda
     * é rascunho.
     */
    public const PLAN_NOT_EFFECTIVE_REFUSAL = 'O plano de %s ainda não está em vigor: ative a versão dele (aba Versões dos Planos da operação) antes de enviar medição.';

    /**
     * A medição fica ligada para sempre à versão do plano em que foi enviada;
     * a correção escolhe outra linha dessa mesma versão.
     */
    public const CAPTURED_VERSION_REFUSAL = 'A medição foi enviada sob a %s do plano de %s e continua ligada a ela: escolha uma medição prevista dessa versão.';

    /**
     * Medição enviada sob uma versão já substituída fica na linha em que foi
     * enviada.
     */
    public const SUPERSEDED_VERSION_LINE_CHANGE_REFUSAL = 'A medição foi enviada sob a %s do plano de %s, que já foi substituída: a medição prevista dela não muda mais. Para medir outra competência, recuse esta medição e envie uma nova sob a versão vigente.';

    /**
     * Uma medição prevista (em qualquer versão do plano) tem no máximo uma
     * medição de pé.
     */
    public const LINE_ALREADY_CLAIMED_REFUSAL = 'A medição %s (%s) do cronograma de %s já está ocupada pela medição #%d. Escolha outra medição prevista ou aguarde a recusa daquela medição.';

    protected $attributes = [
        'storage_disk' => DocumentStorageService::DEFAULT_PRIVATE_DISK,
    ];

    protected $fillable = [
        'measurement_id',
        'plan_set_id',
        'plan_line_id',
        'filename',
        'storage_path',
        'storage_disk',
        'sha256',
        'mime_type',
        'size',
        'uploaded_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $asset): void {
            $newFile = $asset->isDirty(['storage_path', 'storage_disk']) && filled($asset->storage_path);
            $validation = app(MeasurementFileValidationService::class);

            if ($newFile) {
                $validation->compensateAssetOnRollback((string) $asset->storage_path, $asset->resolved_storage_disk);
            }

            try {
                $measurement = $asset->measurement()->first();

                if ($measurement?->hasApprovedEngineering()) {
                    throw new MeasurementWorkflowException('Os arquivos aprovados pela Engenharia estão bloqueados. Devolva a medição à Engenharia para alterá-los.', [
                        'measurement_id' => $measurement->getKey(),
                        'asset_id' => $asset->getKey(),
                    ]);
                }

                if ($asset->exists
                    && $asset->isDirty(['plan_set_id', 'plan_line_id'])
                    && $measurement?->payments()->exists()) {
                    throw new MeasurementWorkflowException(self::PAID_CONTEXT_CHANGE_REFUSAL, [
                        'measurement_id' => $measurement->getKey(),
                        'asset_id' => $asset->getKey(),
                    ]);
                }

                $asset->bindPlanContext($measurement);

                if ($newFile) {
                    $asset->validateNewFile($validation);
                }
            } catch (Throwable $exception) {
                if ($newFile) {
                    $validation->discardUnreferencedAsset((string) $asset->storage_path, $asset->resolved_storage_disk);
                }

                throw $exception;
            }

            if (blank($asset->filename) && filled($asset->storage_path)) {
                $asset->filename = basename((string) $asset->storage_path);
            }

            if (blank($asset->uploaded_at)) {
                $asset->uploaded_at = now();
            }
        });

        static::deleting(function (self $asset): void {
            $measurement = $asset->measurement()->first();

            if ($measurement?->hasApprovedEngineering()) {
                throw new MeasurementWorkflowException('Um arquivo aprovado pela Engenharia não pode ser removido sem devolver a medição à Engenharia.', [
                    'measurement_id' => $measurement->getKey(),
                    'asset_id' => $asset->getKey(),
                ]);
            }

            if ($measurement?->payments()->exists()) {
                throw new MeasurementWorkflowException(self::PAID_FILE_REMOVAL_REFUSAL, [
                    'measurement_id' => $measurement->getKey(),
                    'asset_id' => $asset->getKey(),
                ]);
            }
        });

        static::created(fn (self $asset) => $asset->auditAssetChange('measurement_asset_created', null));
        static::updated(function (self $asset): void {
            if ($asset->wasChanged(['storage_path', 'storage_disk', 'sha256', 'plan_set_id', 'plan_line_id'])) {
                $asset->auditAssetChange('measurement_asset_replaced', $asset->getOriginal('sha256'));
            }
        });
        static::deleted(fn (self $asset) => $asset->auditAssetChange('measurement_asset_removed', $asset->sha256));
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function planSet(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanSet::class, 'plan_set_id');
    }

    public function planLine(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanLine::class, 'plan_line_id');
    }

    /**
     * A versão do plano em que a medição foi enviada para este empreendimento.
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanVersion::class, 'plan_version_id');
    }

    public function fileMigrationJournal(): MorphOne
    {
        return $this->morphOne(MeasurementFileMigration::class, 'migratable');
    }

    public function getResolvedStorageDiskAttribute(): string
    {
        return $this->storage_disk ?: 'public';
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
        return 'size';
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

    /**
     * Confere o arquivo novo -- armazenamento, tipo real, tamanho, antivírus e
     * SHA-256 -- e recusa dizendo de qual empreendimento ele é.
     *
     * O envio grava um arquivo por empreendimento, e a recusa sai com a chave
     * `asset`, que não é campo do formulário: vira uma notificação só. Numa
     * operação com Torre Alfa e Torre Beta, "O arquivo foi bloqueado pelo
     * antivírus" não diz qual trocar, e a recusa descarta todos os arquivos da
     * tentativa. O registro crítico do antivírus, feito pelo serviço, leva a
     * medição e o plano no contexto do log enquanto este arquivo é conferido.
     */
    private function validateNewFile(MeasurementFileValidationService $validation): void
    {
        $logContext = [
            'measurement_id' => $this->measurement_id,
            'plan_set_id' => $this->plan_set_id,
        ];

        Log::withContext($logContext);

        try {
            $validation->validateAsset((string) $this->storage_path, $this->resolved_storage_disk);

            if (! is_string($this->sha256) || strlen($this->sha256) !== 64) {
                throw ValidationException::withMessages(['asset' => 'Não foi possível calcular o SHA-256 do arquivo da medição.']);
            }
        } catch (ValidationException $exception) {
            throw $this->withDevelopmentLabel($exception);
        } finally {
            Log::withoutContext(array_keys($logContext));
        }
    }

    /**
     * A recusa com o empreendimento na frente de cada mensagem, quando a
     * operação tem mais de um -- com um só, não há o que distinguir e a frase
     * fica como sempre foi.
     */
    private function withDevelopmentLabel(ValidationException $exception): ValidationException
    {
        $planSet = filled($this->plan_set_id)
            ? MeasurementPlanSet::query()->with('construction')->find($this->plan_set_id)
            : null;

        if (! $planSet instanceof MeasurementPlanSet
            || MeasurementPlanSet::query()->where('operation_id', $planSet->operation_id)->count() < 2) {
            return $exception;
        }

        $label = $planSet->construction?->development_name ?? $planSet->name;

        return ValidationException::withMessages(array_map(
            fn (array $messages): array => array_map(fn (string $message): string => "{$label}: {$message}", $messages),
            $exception->errors(),
        ));
    }

    /**
     * Amarra o arquivo ao plano, à versão e à linha -- sempre a partir da
     * linha escolhida, nunca do que o formulário mandar para plano ou versão.
     *
     * - na criação, a linha precisa ser da versão vigente: é ela que a medição
     *   leva para sempre (`plan_version_id`);
     * - depois, a versão não muda, e outra linha só vale se for da mesma versão;
     * - enquanto a medição está de pé (qualquer situação menos a recusa
     *   terminal), o arquivo ocupa a linhagem da linha (`line_claim_key`); a
     *   unique do banco recusa a segunda ocupação, e a conferência abaixo dá o
     *   motivo antes dela.
     *
     * Quem grava arquivo já segura o lock da Operation (envio, edição), então
     * nenhuma ativação de versão nem outra ocupação acontece no meio.
     */
    private function bindPlanContext(?Measurement $measurement): void
    {
        if (blank($this->plan_line_id)) {
            if (! $this->exists && filled($this->plan_set_id) && blank($this->plan_version_id)) {
                $this->plan_version_id = MeasurementPlanVersion::query()
                    ->where('plan_set_id', $this->plan_set_id)
                    ->active()
                    ->value('id');
            }

            $this->line_claim_key = null;

            return;
        }

        $line = MeasurementPlanLine::query()->with('planSet.construction')->find($this->plan_line_id);
        $version = $line instanceof MeasurementPlanLine
            ? MeasurementPlanVersion::query()->whereKey($line->plan_version_id)->sharedLock()->first()
            : null;

        if (! $line instanceof MeasurementPlanLine || ! $version instanceof MeasurementPlanVersion) {
            throw ValidationException::withMessages(['asset' => 'A medição prevista escolhida não está mais disponível.']);
        }

        $label = $line->planSet?->construction?->development_name ?? $line->planSet?->name ?? 'o empreendimento';

        if ($measurement instanceof Measurement && (int) $line->operation_id !== (int) $measurement->operation_id) {
            throw new MeasurementWorkflowException("A medição prevista escolhida não pertence à operação desta medição ({$label}).", [
                'measurement_id' => $measurement->getKey(),
                'plan_line_id' => $line->getKey(),
            ]);
        }

        if (filled($this->plan_set_id) && (int) $this->plan_set_id !== (int) $line->plan_set_id) {
            throw new MeasurementWorkflowException("A medição prevista escolhida não pertence ao plano de {$label}.", [
                'plan_set_id' => $this->plan_set_id,
                'plan_line_id' => $line->getKey(),
            ]);
        }

        $this->plan_set_id = $line->plan_set_id;
        $capturedVersionId = $this->exists ? $this->getRawOriginal('plan_version_id') : null;

        if ($capturedVersionId === null) {
            if ($version->status !== MeasurementPlanVersionStatus::Active) {
                $neverEffective = $version->status === MeasurementPlanVersionStatus::Draft
                    && ! MeasurementPlanVersion::query()->where('plan_set_id', $line->plan_set_id)->active()->exists();

                throw new MeasurementWorkflowException(sprintf($neverEffective ? self::PLAN_NOT_EFFECTIVE_REFUSAL : self::SUPERSEDED_PLAN_REFUSAL, $label), [
                    'plan_line_id' => $line->getKey(),
                    'plan_version_id' => $line->plan_version_id,
                ]);
            }

            $this->plan_version_id = $line->plan_version_id;
        } elseif ((int) $line->plan_version_id !== (int) $capturedVersionId || $this->isDirty('plan_version_id')) {
            $captured = MeasurementPlanVersion::query()->find($capturedVersionId);

            throw new MeasurementWorkflowException(sprintf(self::CAPTURED_VERSION_REFUSAL, $captured?->label() ?? 'versão', $label), [
                'asset_id' => $this->getKey(),
                'plan_version_id' => $capturedVersionId,
                'plan_line_id' => $line->getKey(),
            ]);
        } elseif ($this->isDirty('plan_line_id') && $version->status !== MeasurementPlanVersionStatus::Active) {
            // A versão em que a medição foi enviada já foi substituída: trocar de
            // linha agora levaria a medição para uma competência que a versão
            // vigente replanejou. A correção é recusar e reenviar sob a vigente.
            throw new MeasurementWorkflowException(sprintf(self::SUPERSEDED_VERSION_LINE_CHANGE_REFUSAL, $version->label(), $label), [
                'asset_id' => $this->getKey(),
                'plan_version_id' => $capturedVersionId,
                'plan_line_id' => $line->getKey(),
            ]);
        }

        $this->line_claim_key = $measurement instanceof Measurement && ! $measurement->holdsPlanLineClaims()
            ? null
            : $line->lineage_key;

        if ($this->line_claim_key === null) {
            return;
        }

        $holder = self::query()
            ->where('line_claim_key', $this->line_claim_key)
            ->when($this->exists, fn ($others) => $others->whereKeyNot($this->getKey()))
            ->value('measurement_id');

        if ($holder !== null && (int) $holder !== (int) $this->measurement_id) {
            throw new MeasurementWorkflowException(sprintf(
                self::LINE_ALREADY_CLAIMED_REFUSAL,
                str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT),
                $line->measurement_date?->format('m/Y') ?? 'sem data',
                $label,
                (int) $holder,
            ), [
                'plan_line_id' => $line->getKey(),
                'holder_measurement_id' => (int) $holder,
            ]);
        }
    }

    private function auditAssetChange(string $event, ?string $oldHash): void
    {
        $measurement = $this->measurement()->first();
        $activity = activity('measurement_assets')
            ->performedOn($this)
            ->withProperties([
                'asset_id' => $this->getKey(),
                'measurement_id' => $this->measurement_id,
                'operation_id' => $measurement?->operation_id,
                'plan_set_id' => $this->plan_set_id,
                'plan_line_id' => $this->plan_line_id,
                'plan_version_id' => $this->plan_version_id,
                'line_claim_key' => $this->line_claim_key,
                'old_sha256' => $oldHash,
                'new_sha256' => $event === 'measurement_asset_removed' ? null : $this->sha256,
                'actor_user_id' => auth()->id(),
            ]);

        if (auth()->user() instanceof User) {
            $activity->causedBy(auth()->user());
        }

        $activity->log($event);
    }
}
