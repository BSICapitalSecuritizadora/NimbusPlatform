<?php

namespace App\Models;

use Database\Factories\GuaranteeGenerationRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Execução de uma extração documental de garantias (§43 do escopo).
 *
 * Espelha {@see ObligationGenerationRun}: a interface mostra o andamento sem
 * bloquear e a falha permite nova tentativa.
 */
class GuaranteeGenerationRun extends Model
{
    /** @use HasFactory<GuaranteeGenerationRunFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Aguardando processamento',
        self::STATUS_RUNNING => 'Processando',
        self::STATUS_COMPLETED => 'Concluído',
        self::STATUS_FAILED => 'Falhou',
    ];

    protected $fillable = [
        'emission_id',
        'document_id',
        'user_id',
        'status',
        'current_step',
        'message',
        'detected_count',
        'conflict_count',
        'is_reprocessing',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_count' => 'integer',
            'conflict_count' => 'integer',
            'is_reprocessing' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
