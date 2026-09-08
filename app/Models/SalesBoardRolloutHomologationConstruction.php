<?php

namespace App\Models;

use App\Enums\SalesBoardRolloutComparisonStatus;
use Database\Factories\SalesBoardRolloutHomologationConstructionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que a homologação encontrou num empreendimento.
 *
 * As posições são guardadas em JSON canônico -- unidades, centavos e
 * competência, sem nenhum dado de comprador. Nada de model serializado: um
 * `SalesBoard` serializado dentro de um JSON quebraria no primeiro campo que a
 * tabela legada ganhasse, e o que se quer guardar é a posição revisada, não o
 * objeto que a carregava.
 */
class SalesBoardRolloutHomologationConstruction extends Model
{
    /** @use HasFactory<SalesBoardRolloutHomologationConstructionFactory> */
    use HasFactory;

    /**
     * A versão do formato das posições persistidas.
     *
     * Existe para que um JSON gravado hoje continue legível depois de o formato
     * mudar: sem versão, a única saída seria adivinhar pela presença das chaves.
     */
    public const POSITION_SCHEMA_VERSION = 1;

    protected $fillable = [
        'sales_board_rollout_homologation_id',
        'construction_id',
        'is_ready',
        'blocker_codes',
        'blocker_message',
        'source_fingerprint',
        'snapshot_fingerprint',
        'comparison_status',
        'legacy_position',
        'derived_position',
        'position_delta',
        'legacy_sales_board_id',
        'legacy_reference_month',
        'accepted_difference',
        'difference_reason',
        'accepted_at',
        'accepted_by_user_id',
        'has_cycle_at_or_after_start',
        'has_cancelled_cycle_at_or_after_start',
        'latest_legacy_board_month',
    ];

    protected function casts(): array
    {
        return [
            'is_ready' => 'boolean',
            'blocker_codes' => 'array',
            'comparison_status' => SalesBoardRolloutComparisonStatus::class,
            'legacy_position' => 'array',
            'derived_position' => 'array',
            'position_delta' => 'array',
            'legacy_reference_month' => 'immutable_date',
            'accepted_difference' => 'boolean',
            'accepted_at' => 'immutable_datetime',
            'has_cycle_at_or_after_start' => 'boolean',
            'has_cancelled_cycle_at_or_after_start' => 'boolean',
            'latest_legacy_board_month' => 'immutable_date',
        ];
    }

    public function homologation(): BelongsTo
    {
        return $this->belongsTo(
            SalesBoardRolloutHomologation::class,
            'sales_board_rollout_homologation_id',
        );
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function legacySalesBoard(): BelongsTo
    {
        return $this->belongsTo(SalesBoard::class, 'legacy_sales_board_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * @return list<string>
     */
    public function blockerCodes(): array
    {
        return array_values(array_map('strval', $this->blocker_codes ?? []));
    }

    /**
     * A linha está pronta para sustentar uma homologação?
     *
     * Prontidão da fonte **e** comparação resolvida. Uma diferença entendida é
     * tão homologável quanto uma coincidência; o que não é homologável é uma
     * diferença que ninguém olhou.
     */
    public function isHomologable(): bool
    {
        return $this->is_ready && ! $this->requiresAcknowledgement();
    }

    public function requiresAcknowledgement(): bool
    {
        return $this->comparison_status->requiresAcknowledgement() && ! $this->accepted_difference;
    }

    /**
     * O delta de um balde, na forma que a tela mostra.
     *
     * @return array{units: int, valueCents: int}|null
     */
    public function deltaFor(string $bucket): ?array
    {
        $delta = $this->position_delta['buckets'][$bucket] ?? null;

        return is_array($delta)
            ? ['units' => (int) ($delta['units'] ?? 0), 'valueCents' => (int) ($delta['value_cents'] ?? 0)]
            : null;
    }

    public function hasAnyDelta(): bool
    {
        return (bool) ($this->position_delta['has_difference'] ?? false);
    }
}
