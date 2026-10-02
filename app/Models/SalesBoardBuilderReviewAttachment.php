<?php

namespace App\Models;

use App\Enums\MalwareScanStatus;
use Database\Factories\SalesBoardBuilderReviewAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Um arquivo da resposta da construtora, anexado à validação no envio.
 *
 * É a prova que dá ao registro interno o peso de "validação da construtora":
 * nasce junto com o envio, no disco privado, já varrido pelo antivírus e com os
 * metadados derivados do arquivo gravado. Depois disso não muda e não some --
 * a validação enviada é declaração, e a evidência dela também.
 *
 * O download passa por uma rota autenticada que confere a permissão de ver o
 * Quadro; o caminho no disco nunca vai para a tela.
 */
class SalesBoardBuilderReviewAttachment extends Model
{
    /** @use HasFactory<SalesBoardBuilderReviewAttachmentFactory> */
    use HasFactory, LogsActivity;

    protected $attributes = [
        'scan_status' => MalwareScanStatus::Pending->value,
    ];

    protected $fillable = [
        'sales_board_builder_review_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'checksum',
        'scan_status',
        'uploaded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A builder response attachment is immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Builder response attachments cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scan_status' => MalwareScanStatus::class,
        ];
    }

    /**
     * Grava em `sales_board`, a categoria protegida do módulo: o que foi
     * anexado, por quem e com qual conteúdo (o SHA-256) precisa sobreviver os
     * mesmos sete anos da validação que ele sustenta.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales_board')
            ->logOnly([
                'sales_board_builder_review_id',
                'original_name',
                'mime_type',
                'size_bytes',
                'checksum',
                'uploaded_by_user_id',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(SalesBoardBuilderReview::class, 'sales_board_builder_review_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Só o arquivo aprovado pela varredura é servido.
     */
    public function isAvailable(): bool
    {
        return $this->scan_status === MalwareScanStatus::Clean;
    }

    public function humanSize(): string
    {
        return Number::fileSize((int) $this->size_bytes, precision: 1);
    }

    public function downloadUrl(): string
    {
        return route('admin.sales-board-builder-responses.download', ['attachment' => $this->getKey()]);
    }
}
