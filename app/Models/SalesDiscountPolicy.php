<?php

namespace App\Models;

use App\Services\SalesBoards\SalesDiscountPolicyResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SalesDiscountPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desconto comercial máximo que a BSI autorizava para um empreendimento num
 * período.
 *
 * Append-only, como o histórico de valores das unidades: a pergunta que estas
 * linhas respondem é "o que estava autorizado quando aquela venda aconteceu", e
 * editar a linha apagaria a resposta.
 *
 * Vigência fechada `[effective_from, effective_until]`, os dois dias incluídos.
 * Uma política nova substitui a anterior a partir do próprio início, e a
 * anterior não volta a valer depois; quem decide qual linha responde numa data
 * é o {@see SalesDiscountPolicyResolver}. Linhas
 * anteriores ao fim explícito têm `effective_until` nulo e valem até a próxima
 * começar.
 *
 * O percentual é limite máximo, não desconto concedido: uma venda pode ter
 * desconto menor, nunca maior.
 */
class SalesDiscountPolicy extends Model
{
    /** @use HasFactory<SalesDiscountPolicyFactory> */
    use HasFactory;

    public const MINIMUM_DISCOUNT_PERCENT = 0;

    public const MAXIMUM_DISCOUNT_PERCENT = 100;

    protected $fillable = [
        'construction_id',
        'maximum_discount_percent',
        'effective_from',
        'effective_until',
        'reason',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'maximum_discount_percent' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function getFormattedMaximumDiscountPercentAttribute(): string
    {
        return number_format((float) $this->maximum_discount_percent, 2, ',', '.').'%';
    }

    /**
     * A data está dentro do período registrado, os dois extremos incluídos.
     *
     * Não diz se a política é a que responde pela data: uma política mais nova
     * pode tê-la substituído. Isso é com o resolvedor.
     */
    public function coversDate(CarbonInterface $date): bool
    {
        $day = $date->toDateString();
        $start = $this->effective_from?->toDateString();

        if (($start === null) || ($start > $day)) {
            return false;
        }

        $end = $this->effective_until?->toDateString();

        return ($end === null) || ($end >= $day);
    }

    /**
     * Dias de calendário do período registrado, contando início e fim.
     *
     * Nulo para as linhas anteriores ao fim explícito.
     */
    public function durationInDays(): ?int
    {
        if (($this->effective_from === null) || ($this->effective_until === null)) {
            return null;
        }

        return self::inclusiveDayCount($this->effective_from, $this->effective_until);
    }

    /**
     * Regra única da duração, usada pelo formulário e pela listagem: contagem
     * inclusiva, porque a política vale no dia do início e no dia do fim. De
     * 24/09/2026 a 31/12/2026 são 99 dias; início igual ao fim é 1 dia.
     *
     * Nulo quando o fim é anterior ao início -- não existe duração negativa para
     * exibir, existe um período inválido.
     */
    public static function inclusiveDayCount(CarbonInterface $from, CarbonInterface $until): ?int
    {
        $start = CarbonImmutable::parse($from->toDateString());
        $end = CarbonImmutable::parse($until->toDateString());

        if ($end->lessThan($start)) {
            return null;
        }

        return (int) $start->diffInDays($end) + 1;
    }

    public static function formatDayCount(int $days): string
    {
        return $days === 1 ? '1 dia' : number_format($days, 0, ',', '.').' dias';
    }
}
