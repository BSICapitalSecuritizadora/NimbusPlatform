<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração de SLA por etapa.
 *
 * Fim de semana e feriado NÃO são decididos aqui: a fonte de verdade é o calendário
 * corporativo (`measurements.sla.calendar_code`) resolvido pelo BusinessCalendarService.
 * As colunas `exclude_weekends` e `exclude_holidays` são legado inerte — permanecem no
 * schema por compatibilidade, não são lidas por nenhum cálculo e alterá-las não muda
 * prazo algum (ver MeasurementSlaBusinessTimeTest). Manter assim evita um segundo
 * modelo de calendário concorrendo com o corporativo.
 *
 * A configuração é retroativa por contrato: `MeasurementSlaService` a relê a cada
 * avaliação, então uma alteração reprojeta o prazo de medições já em andamento. Antes
 * de expor edição administrativa na interface, reavaliar snapshot por ciclo, histórico
 * e auditoria.
 */
class SlaConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'stage',
        'duration_value',
        'duration_unit',
        'warning_threshold_percent',
        'escalation_threshold_percent',
        // @deprecated Legado inerte — o calendário corporativo decide dia útil.
        'exclude_weekends',
        'exclude_holidays',
        'exclude_paused_time',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'duration_value' => 'integer',
            'warning_threshold_percent' => 'integer',
            'escalation_threshold_percent' => 'integer',
            'exclude_weekends' => 'boolean',
            'exclude_holidays' => 'boolean',
            'exclude_paused_time' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function durationInSeconds(): int
    {
        $hours = $this->duration_unit === 'days' ? $this->duration_value * 24 : $this->duration_value;

        return $hours * 3600;
    }

    public static function activeForStage(int $stage): ?self
    {
        return static::query()->where('stage', $stage)->where('is_active', true)->first();
    }
}
