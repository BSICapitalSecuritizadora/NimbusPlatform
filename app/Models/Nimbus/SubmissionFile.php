<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubmissionFile extends Model
{
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected const DOCUMENT_TYPE_LABELS = [
        'BALANCE_SHEET' => 'Último Balanço',
        'DRE' => 'DRE (Demonstração do Resultado do Exercício)',
        'POLICIES' => 'Políticas',
        'CNPJ_CARD' => 'Cartão CNPJ',
        'POWER_OF_ATTORNEY' => 'Procuração',
        'MINUTES' => 'Ata',
        'ARTICLES_OF_INCORPORATION' => 'Contrato Social',
        'BYLAWS' => 'Estatuto',
        'OTHER' => 'Documento Complementar',
    ];

    protected $table = 'nimbus_submission_files';

    protected $guarded = ['id'];

    protected $casts = [
        'visible_to_user' => 'boolean',
        'uploaded_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function documentTypeLabels(): array
    {
        return static::DOCUMENT_TYPE_LABELS;
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'nimbus_submission_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SubmissionFileVersion::class, 'nimbus_submission_file_id');
    }

    public function getDocumentTypeLabelAttribute(): string
    {
        $documentType = strtoupper((string) $this->document_type);

        return static::DOCUMENT_TYPE_LABELS[$documentType] ?? ((string) $this->original_name ?: 'Arquivo');
    }
}
