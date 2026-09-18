<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

/**
 * Quantização MONETÁRIA contratual da curva de PU.
 *
 * O Termo de Securitização fixa VNb, J, AMi e SDa em "8 (oito) casas decimais,
 * SEM arredondamento". Sem arredondamento é corte: `DecimalRounder::truncate()`
 * descarta as casas excedentes em direção a zero, preservando o sinal.
 *
 * Esta política é do CÁLCULO, não da apresentação. `PuDecimalPresenter` continua
 * existindo, mas deixa de decidir valor econômico: ele passa a formatar uma
 * string que a engine já quantizou. Se a exibição arredondasse um valor que a
 * engine truncou, tela e pagamento discordariam na 8ª casa.
 *
 * Não confundir com as escalas dos FATORES (Fator DI em 8, Fator Spread em 9,
 * Fator de Juros em 9), que são arredondamento e vivem em
 * {@see CdiFactorCompositionService}.
 */
final class PuPrecisionPolicy
{
    /** Casas contratuais de VNb, J, AMi e SDa. */
    public const UNIT_VALUE_SCALE = 8;

    public function __construct(
        private readonly DecimalRounder $rounder,
    ) {}

    /**
     * VNb, J, AMi, SDa e o PU deles derivado: truncados em 8 casas e devolvidos
     * na escala de cálculo, para que sigam compondo sem reintroduzir cauda.
     *
     * Idempotente: quantizar um valor já quantizado devolve o mesmo valor.
     */
    public function unitValue(string $value): string
    {
        return $this->rounder->normalize(
            $this->rounder->truncate($value, self::UNIT_VALUE_SCALE),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    /**
     * Descritivo das regras monetárias para a memória de cálculo. Não participa
     * de nenhuma conta.
     *
     * @return array{unit_base_value:int, interest_unit_value:int, amortization_unit_value:int, residual_unit_value:int, quantization:string}
     */
    public function rules(): array
    {
        return [
            'unit_base_value' => self::UNIT_VALUE_SCALE,
            'interest_unit_value' => self::UNIT_VALUE_SCALE,
            'amortization_unit_value' => self::UNIT_VALUE_SCALE,
            'residual_unit_value' => self::UNIT_VALUE_SCALE,
            'quantization' => 'truncate_toward_zero',
        ];
    }
}
