<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    /** @use HasFactory<\Database\Factories\ExpenseFactory> */
    use HasFactory;

    public const CATEGORY_OPTIONS = [
        'Agente Fiduciário' => 'Agente Fiduciário',
        'Servicer' => 'Servicer',
        'Escriturador' => 'Escriturador',
        'Patrimônio Separado' => 'Patrimônio Separado',
        'Custodiante' => 'Custodiante',
        'Fee - Securitizadora' => 'Fee - Securitizadora',
        'Assessor Jurídico' => 'Assessor Jurídico',
        'Cetip' => 'Cetip',
        'Coordenador Líder' => 'Coordenador Líder',
        'Contabilidade' => 'Contabilidade',
        'Engenharia' => 'Engenharia',
        'Custódia da CCI' => 'Custódia da CCI',
        'AGT' => 'AGT',
        'Cartório' => 'Cartório',
    ];

    public const PERIOD_SINGLE = 'single';

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_QUARTERLY = 'quarterly';

    public const PERIOD_SEMIANNUAL = 'semiannual';

    public const PERIOD_ANNUAL = 'annual';

    public const PERIOD_OPTIONS = [
        self::PERIOD_SINGLE => 'Único',
        self::PERIOD_MONTHLY => 'Mensal',
        self::PERIOD_QUARTERLY => 'Trimestral',
        self::PERIOD_SEMIANNUAL => 'Semestral',
        self::PERIOD_ANNUAL => 'Anual',
    ];

    protected $fillable = [
        'emission_id',
        'expense_service_provider_id',
        'category',
        'period',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ExpenseServiceProvider::class, 'expense_service_provider_id');
    }

    public static function isRecurringPeriod(?string $period): bool
    {
        return filled($period) && $period !== self::PERIOD_SINGLE;
    }
}
