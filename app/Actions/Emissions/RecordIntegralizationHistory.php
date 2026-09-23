<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Enums\IntegralizationSource;
use App\Models\Emission;
use App\Models\IntegralizationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Regra única de gravação do Histórico de Integralizações.
 *
 * A planilha e o cadastro manual chegam aqui já convertidos do seu formato de
 * entrada (célula de planilha, máscara pt-BR) para decimal canônico -- dígitos
 * com ponto, sem separador de milhar. Daqui em diante os dois caminhos são o
 * mesmo: escala das colunas, derivação do Valor Financeiro, validação e
 * gravação. Nada passa por `float`.
 *
 * A data identifica a integralização dentro da emissão: é por ela que a
 * reimportação encontra a linha a atualizar. Por isso o cadastro manual recusa
 * uma data já ocupada em vez de criar uma segunda linha -- a planilha seguinte
 * atualizaria uma delas às cegas.
 *
 * A emissão é travada antes de ler a data e o saldo emitido, e a gravação
 * acontece na mesma transação: dois envios simultâneos (duplo clique, reenvio
 * do Livewire) não passam juntos pela checagem. A busca pela data também é
 * leitura travada, para enxergar o último commit mesmo dentro de uma transação
 * externa que já tenha lido antes (REPEATABLE READ do MySQL).
 */
class RecordIntegralizationHistory
{
    /**
     * Escalas das colunas de `integralization_histories`, todas `DECIMAL(18, x)`.
     */
    public const QUANTITY_SCALE = 4;

    public const UNIT_VALUE_SCALE = 8;

    public const FINANCIAL_VALUE_SCALE = 2;

    private const COLUMN_PRECISION = 18;

    private const PLAIN_DECIMAL_PATTERN = '/^-?\d+(\.\d+)?$/';

    public function __construct(private DecimalRounder $rounder) {}

    /**
     * Cadastro de uma integralização nova: a data já ocupada na emissão é
     * conflito, nunca atualização.
     *
     * @param  array{date?: mixed, quantity?: mixed, unit_value?: mixed, financial_value?: mixed, investor_fund?: mixed}  $entry
     *
     * @throws ValidationException
     */
    public function create(Emission $emission, array $entry, IntegralizationSource $source): IntegralizationHistory
    {
        $attributes = $this->prepare($entry);

        return DB::transaction(function () use ($emission, $attributes, $source): IntegralizationHistory {
            $lockedEmission = $this->lockEmission($emission);

            if ($lockedEmission->integralizationHistories()->whereDate('date', $attributes['date'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'date' => sprintf(
                        'Já existe uma integralização em %s nesta emissão. A importação identifica a integralização pela data, por isso não é possível registrar outra no mesmo dia.',
                        self::formatDate($attributes['date']),
                    ),
                ]);
            }

            return $this->persist($lockedEmission->integralizationHistories()->make(), $attributes, $source);
        });
    }

    /**
     * Linha de planilha: atualiza a integralização da data ou cria uma nova.
     *
     * Com mais de uma integralização na mesma data (cadastro manual anterior a
     * esta regra), a planilha não tem como saber qual atualizar e recusa.
     *
     * @param  array{date?: mixed, quantity?: mixed, unit_value?: mixed, financial_value?: mixed, investor_fund?: mixed}  $entry
     *
     * @throws ValidationException
     */
    public function createOrUpdateForDate(Emission $emission, array $entry, IntegralizationSource $source): IntegralizationHistory
    {
        $attributes = $this->prepare($entry);

        return DB::transaction(function () use ($emission, $attributes, $source): IntegralizationHistory {
            $lockedEmission = $this->lockEmission($emission);

            $integralizationsOnDate = $lockedEmission->integralizationHistories()
                ->whereDate('date', $attributes['date'])
                ->orderBy('id')
                ->limit(2)
                ->lockForUpdate()
                ->get();

            if ($integralizationsOnDate->count() > 1) {
                throw ValidationException::withMessages([
                    'date' => sprintf(
                        'Há mais de uma integralização em %s nesta emissão; a planilha não identifica qual delas atualizar.',
                        self::formatDate($attributes['date']),
                    ),
                ]);
            }

            return $this->persist(
                $integralizationsOnDate->first() ?? $lockedEmission->integralizationHistories()->make(),
                $attributes,
                $source,
            );
        });
    }

    /**
     * Valor Financeiro que a gravação produziria para esta quantidade e este
     * PU. O modal usa a mesma conta na prévia; `null` enquanto faltar valor.
     */
    public function financialValueFor(?string $quantity, ?string $unitValue): ?string
    {
        if (! self::isPlainDecimal($quantity) || ! self::isPlainDecimal($unitValue)) {
            return null;
        }

        return $this->deriveFinancialValue(
            $this->rounder->round($quantity, self::QUANTITY_SCALE),
            $this->rounder->round($unitValue, self::UNIT_VALUE_SCALE),
        );
    }

    /**
     * @param  array{date?: mixed, quantity?: mixed, unit_value?: mixed, financial_value?: mixed, investor_fund?: mixed}  $entry
     * @return array{date: string, quantity: string, unit_value: ?string, financial_value: ?string, investor_fund: ?string}
     *
     * @throws ValidationException
     */
    private function prepare(array $entry): array
    {
        $entry = [
            'date' => self::blankToNull($entry['date'] ?? null),
            'quantity' => self::blankToNull($entry['quantity'] ?? null),
            'unit_value' => self::blankToNull($entry['unit_value'] ?? null),
            'financial_value' => self::blankToNull($entry['financial_value'] ?? null),
            'investor_fund' => self::blankToNull($entry['investor_fund'] ?? null),
        ];

        Validator::make($entry, [
            'date' => ['required', 'date_format:Y-m-d'],
            'quantity' => ['required', 'regex:'.self::PLAIN_DECIMAL_PATTERN],
            'unit_value' => ['nullable', 'regex:'.self::PLAIN_DECIMAL_PATTERN],
            'financial_value' => ['nullable', 'regex:'.self::PLAIN_DECIMAL_PATTERN],
            'investor_fund' => ['nullable', 'string', 'max:255'],
        ], [
            'date.required' => 'Informe a data de integralização.',
            'date.date_format' => 'Informe uma data de integralização válida.',
            'quantity.required' => 'Informe a quantidade a integralizar.',
            'quantity.regex' => 'Informe uma quantidade válida.',
            'unit_value.regex' => 'Informe um PU válido.',
            'financial_value.regex' => 'Informe um valor financeiro válido.',
            'investor_fund.max' => 'O nome do fundo do investidor deve ter no máximo 255 caracteres.',
        ])->validate();

        $quantity = $this->rounder->round($entry['quantity'], self::QUANTITY_SCALE);
        $unitValue = $entry['unit_value'] !== null
            ? $this->rounder->round($entry['unit_value'], self::UNIT_VALUE_SCALE)
            : null;

        $errors = [];

        if (bccomp($quantity, '0', self::QUANTITY_SCALE) <= 0) {
            $errors['quantity'] = 'A quantidade deve ser maior que zero.';
        } elseif (! self::fitsColumn($quantity, self::QUANTITY_SCALE)) {
            $errors['quantity'] = self::integerDigitsMessage('A quantidade', self::QUANTITY_SCALE);
        }

        if (($unitValue !== null) && (bccomp($unitValue, '0', self::UNIT_VALUE_SCALE) <= 0)) {
            $errors['unit_value'] = 'O PU deve ser maior que zero.';
        } elseif (($unitValue !== null) && (! self::fitsColumn($unitValue, self::UNIT_VALUE_SCALE))) {
            $errors['unit_value'] = self::integerDigitsMessage('O PU', self::UNIT_VALUE_SCALE);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $financialValue = $entry['financial_value'] !== null
            ? $this->rounder->round($entry['financial_value'], self::FINANCIAL_VALUE_SCALE)
            : $this->deriveFinancialValue($quantity, $unitValue);

        if (($financialValue !== null) && (! self::fitsColumn($financialValue, self::FINANCIAL_VALUE_SCALE))) {
            throw ValidationException::withMessages([
                'financial_value' => self::integerDigitsMessage('O Valor Financeiro', self::FINANCIAL_VALUE_SCALE),
            ]);
        }

        return [
            'date' => $entry['date'],
            'quantity' => $quantity,
            'unit_value' => $unitValue,
            'financial_value' => $financialValue,
            'investor_fund' => $entry['investor_fund'],
        ];
    }

    /**
     * O MySQL roda em modo estrito: valor acima do `DECIMAL(18, x)` seria erro
     * de banco, não mensagem para o usuário.
     */
    private static function fitsColumn(string $value, int $scale): bool
    {
        $integerDigits = ltrim(explode('.', ltrim($value, '-'), 2)[0], '0');

        return strlen($integerDigits) <= (self::COLUMN_PRECISION - $scale);
    }

    private static function integerDigitsMessage(string $subject, int $scale): string
    {
        return sprintf('%s deve ter no máximo %d dígitos antes da vírgula.', $subject, self::COLUMN_PRECISION - $scale);
    }

    /**
     * Quantidade × PU, ambos já na escala da coluna, arredondado para o
     * centavo com meio para cima (a mesma regra do DECIMAL do MySQL, que era
     * quem arredondava o produto da importação antes desta classe).
     */
    private function deriveFinancialValue(string $quantity, ?string $unitValue): ?string
    {
        if ($unitValue === null) {
            return null;
        }

        return $this->rounder->round(
            bcmul($quantity, $unitValue, self::QUANTITY_SCALE + self::UNIT_VALUE_SCALE),
            self::FINANCIAL_VALUE_SCALE,
        );
    }

    /**
     * @param  array{date: string, quantity: string, unit_value: ?string, financial_value: ?string, investor_fund: ?string}  $attributes
     */
    private function persist(IntegralizationHistory $integralizationHistory, array $attributes, IntegralizationSource $source): IntegralizationHistory
    {
        $integralizationHistory->fill($attributes);
        $integralizationHistory->recordedThrough = $source;
        $integralizationHistory->save();

        return $integralizationHistory;
    }

    private function lockEmission(Emission $emission): Emission
    {
        return Emission::query()
            ->whereKey($emission->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private static function blankToNull(mixed $value): mixed
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    public static function isPlainDecimal(?string $value): bool
    {
        return ($value !== null) && (preg_match(self::PLAIN_DECIMAL_PATTERN, $value) === 1);
    }

    private static function formatDate(string $date): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date)->format('d/m/Y');
    }
}
