<?php

namespace App\Models;

use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Activity;

/**
 * A reconciliation that was confirmed: which file, by whom, and what it moved.
 *
 * A summary on purpose. What each contract or installment actually had before
 * the import changed it lives on that record's own activity log; this is the
 * index that says which run to go looking in.
 */
class ImportRun extends Model
{
    /** @use HasFactory<ImportRunFactory> */
    use HasFactory;

    public const TYPE_CONTRACTS = 'contracts';

    public const TYPE_CONTRACT_INSTALLMENTS = 'contract-installments';

    /**
     * The run wrote something and none of it was flagged as critical.
     */
    public const RESULT_COMPLETED = 'concluida';

    /**
     * The run wrote something and part of it changed a field the reconciliation
     * treats as critical.
     */
    public const RESULT_CRITICAL = 'concluida_com_criticas';

    /**
     * The file was already the registered position. Recorded precisely because
     * proving that nothing moved is the point of a monthly conciliation.
     */
    public const RESULT_UNCHANGED = 'sem_alteracoes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'file_name',
        'checksum',
        'batch_uuid',
        'user_id',
        'contract_id',
        'records_analyzed',
        'records_created',
        'records_updated',
        'records_unchanged',
        'records_critical',
    ];

    protected function casts(): array
    {
        return [
            'records_analyzed' => 'integer',
            'records_created' => 'integer',
            'records_updated' => 'integer',
            'records_unchanged' => 'integer',
            'records_critical' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Everything the activity log recorded while this execution was running.
     *
     * The join is the batch uuid, not a time window and not the subject: an
     * activity belongs to this run because it was written inside its batch, so a
     * manual edit made on the same record a minute later can never be swept in.
     *
     * A run recorded before the correlation existed has no uuid; Eloquent adds
     * `whereNotNull` to the foreign key, so the relation is simply empty rather
     * than matching every activity that has no batch.
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'batch_uuid', 'batch_uuid');
    }

    /**
     * Whether the individual changes of this execution can be listed at all.
     *
     * False for every run confirmed before the batch correlation existed. There
     * is no safe way to rebuild it afterwards -- same user, same file and same
     * second do not prove an activity came from this run -- so those keep only
     * their summary.
     */
    public function hasChangeCorrelation(): bool
    {
        return filled($this->batch_uuid);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_CONTRACTS => 'Contratos',
            self::TYPE_CONTRACT_INSTALLMENTS => 'Parcelas',
            default => $this->type,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_CONTRACTS => 'Contratos',
            self::TYPE_CONTRACT_INSTALLMENTS => 'Parcelas',
        ];
    }

    /**
     * Whether the run wrote anything. A run that found the position already
     * reconciled is worth recording precisely because it proves nothing moved.
     */
    public function madeChanges(): bool
    {
        return ($this->records_created + $this->records_updated) > 0;
    }

    /**
     * Derived, never stored: the table has no status column and does not need
     * one, because a run only exists when the import went through.
     *
     * A file with conflicts is refused at the conference step and never reaches
     * this table, so there is no "failed" or "with conflicts" outcome to show --
     * only whether the confirmed run moved anything, and whether what it moved
     * touched a critical field.
     */
    public function result(): string
    {
        return match (true) {
            $this->records_critical > 0 => self::RESULT_CRITICAL,
            $this->madeChanges() => self::RESULT_COMPLETED,
            default => self::RESULT_UNCHANGED,
        };
    }

    public function resultLabel(): string
    {
        return self::resultOptions()[$this->result()];
    }

    public function resultColor(): string
    {
        return match ($this->result()) {
            self::RESULT_CRITICAL => 'warning',
            self::RESULT_COMPLETED => 'success',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function resultOptions(): array
    {
        return [
            self::RESULT_COMPLETED => 'Concluída',
            self::RESULT_CRITICAL => 'Concluída com críticas',
            self::RESULT_UNCHANGED => 'Sem alterações',
        ];
    }

    /**
     * The user is kept by id alone, so a deleted account leaves the run without
     * one. The history stays readable either way -- who ran it is unknown, not
     * the run itself.
     */
    public function userName(): string
    {
        return $this->user?->name ?? 'Usuário indisponível';
    }

    /**
     * A full-portfolio file, or the schedule of a single contract.
     *
     * Deliberately not named `scopeSomething`: Eloquent would register it as a
     * local query scope.
     */
    public function coverageLabel(): string
    {
        if ($this->contract_id === null) {
            return 'Carteira completa';
        }

        return $this->contract?->code ?? 'Contrato indisponível';
    }

    /**
     * Whether the very same file content was already processed by another run.
     *
     * Not an error: an idempotent reconciliation re-run is expected to come back
     * with everything unchanged, and seeing that is the point.
     */
    public function fileWasProcessedBefore(): bool
    {
        if (blank($this->checksum)) {
            return false;
        }

        return static::query()
            ->where('checksum', $this->checksum)
            ->whereKeyNot($this->getKey())
            ->exists();
    }
}
