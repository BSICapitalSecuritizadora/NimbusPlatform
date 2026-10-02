<?php

namespace App\Console\Commands;

use App\Enums\ContractStatus;
use App\Enums\ImportRowWarningCode;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\Emission;
use App\Services\SalesBoards\UnitValueResolver;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Imports\PlausibilityVerdict;
use App\Support\Imports\SpreadsheetPlausibility;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\ExchangeContractRecognizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aplica, sem escrever nada, as regras de plausibilidade das importações ao
 * dado já cadastrado.
 *
 * A carga feita pelo leitor antigo de planilhas lia "553,919" como
 * R$ 553.919,00, aceitava ano de dois dígitos e, nos valores de unidade, trocava
 * dia e mês de vigências. As importações novas recusam ou avisam esses casos na
 * entrada; o que já entrou continua cadastrado. Este relatório usa as mesmas
 * regras ({@see SpreadsheetPlausibility}) sobre o banco, para que o dono confira
 * em produção, somente leitura, o que precisa de correção.
 *
 * Imprime a contagem por regra e os ids dos registros -- nunca nome, documento
 * ou valor de cliente. Lê por cursor, sem hidratar models, e não grava nada.
 */
class ImportPlausibilityReport extends Command
{
    protected $signature = 'imports:plausibility-report
                            {--emission= : Limita à emissão informada (id)}';

    protected $description = 'Relata (somente leitura) contratos, parcelas e valores de unidade cadastrados que as regras de plausibilidade das importações recusariam ou avisariam';

    private const CHUNK_SIZE = 500;

    /**
     * Ids por regra, na ordem em que as regras são apresentadas.
     *
     * @var array<string, list<int>>
     */
    private array $findings = [];

    public function handle(UnitValueResolver $unitValueResolver): int
    {
        $emissionId = $this->requestedEmissionId();

        if ($emissionId === false) {
            return self::FAILURE;
        }

        $constructionIds = $this->constructionScope($emissionId);

        if ($constructionIds === []) {
            $this->warn('Nenhum empreendimento encontrado para o filtro informado.');

            return self::SUCCESS;
        }

        $this->checkInstallments($constructionIds);
        $this->checkContracts($constructionIds, $unitValueResolver);
        $this->checkUnitValues($constructionIds);
        $this->checkUnitBaseValues($constructionIds);

        $rows = [];

        foreach ($this->findings as $rule => $ids) {
            sort($ids);

            $rows[] = [$rule, count($ids), implode(', ', $ids)];
        }

        if ($rows === []) {
            $this->info('Nenhum registro cadastrado fora das regras de plausibilidade.');

            return self::SUCCESS;
        }

        $this->table(['Regra', 'Registros', 'Ids'], $rows);
        $this->comment('Somente leitura: nenhum registro foi alterado.');

        return self::SUCCESS;
    }

    /**
     * A emissão pedida: o id, `null` sem filtro, ou `false` quando o valor é
     * ilegível -- e aí o comando falha com a mensagem.
     *
     * O relatório existe para o dono conferir a produção, e um "nada a corrigir"
     * só pode vir da emissão que ele digitou. Convertido com `(int)`, "12a"
     * relatava a Emissão 12 e "abc" virava 0, com "Nenhum empreendimento
     * encontrado" e código de sucesso. Mesma regra de
     * `guarantees:mark-outdated-competences`: valor ilegível -- inclusive o
     * vazio de `--emission=` -- nunca vira todas as Emissões.
     */
    private function requestedEmissionId(): int|false|null
    {
        $value = $this->option('emission');

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || (preg_match('/^[1-9][0-9]*$/', trim($value)) !== 1)) {
            $this->error(sprintf('Emissão inválida: "%s". Informe o id numérico.', is_scalar($value) ? (string) $value : gettype($value)));

            return false;
        }

        $emissionId = (int) trim($value);

        if (! Emission::query()->whereKey($emissionId)->exists()) {
            $this->error(sprintf('Emissão %d não encontrada. Informe o id de uma emissão cadastrada.', $emissionId));

            return false;
        }

        return $emissionId;
    }

    /**
     * Os empreendimentos do relatório: todos, ou os da emissão pedida.
     *
     * @return list<int>
     */
    private function constructionScope(?int $emissionId): array
    {
        return Construction::query()
            ->when($emissionId !== null, fn ($query) => $query->where('emission_id', $emissionId))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Parcelas contra o valor da venda do contrato e o próprio previsto, e datas
     * anteriores a 1990.
     *
     * @param  list<int>  $constructionIds
     */
    private function checkInstallments(array $constructionIds): void
    {
        $installments = DB::table('contract_installments as installment')
            ->join('contracts as contract', 'contract.id', '=', 'installment.contract_id')
            ->whereNull('installment.deleted_at')
            ->whereNull('contract.deleted_at')
            ->whereIn('contract.construction_id', $constructionIds)
            ->orderBy('installment.id')
            ->select([
                'installment.id',
                'installment.expected_value',
                'installment.paid_value',
                'installment.due_date',
                'installment.payment_date',
                'installment.cancellation_date',
                'contract.sale_value',
            ])
            ->cursor();

        foreach ($installments as $installment) {
            $id = (int) $installment->id;

            $this->collect('Parcela', $id, SpreadsheetPlausibility::installment(
                IntegerMoney::cents($installment->expected_value),
                IntegerMoney::cents($installment->paid_value),
                IntegerMoney::cents($installment->sale_value),
            ));

            if (self::anyBeforeMinimumYear($installment->due_date, $installment->payment_date, $installment->cancellation_date)) {
                $this->record('Parcela: data anterior a '.SpreadsheetDate::MINIMUM_YEAR, $id);
            }
        }
    }

    /**
     * Valor da venda contra o valor de tabela da unidade -- com a mesma regra da
     * importação de um contrato novo: a tabela da data da venda e, para o ativo
     * e o quitado, na falta dela, a da data da posição; o contrato de permuta
     * fica fora --, e datas anteriores a 1990.
     *
     * @param  list<int>  $constructionIds
     */
    private function checkContracts(array $constructionIds, UnitValueResolver $unitValueResolver): void
    {
        $chunk = [];

        $contracts = DB::table('contracts')
            ->whereNull('deleted_at')
            ->whereIn('construction_id', $constructionIds)
            ->orderBy('id')
            ->select(['id', 'construction_unit_id', 'status', 'sale_date', 'sale_value', 'cancellation_date'])
            ->cursor();

        foreach ($contracts as $contract) {
            $chunk[] = $contract;

            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->checkContractChunk($chunk, $unitValueResolver);
                $chunk = [];
            }
        }

        $this->checkContractChunk($chunk, $unitValueResolver);
    }

    /**
     * @param  list<object>  $contracts
     */
    private function checkContractChunk(array $contracts, UnitValueResolver $unitValueResolver): void
    {
        if ($contracts === []) {
            return;
        }

        $positionDates = SpreadsheetPlausibility::positionReferenceDates();
        $requests = [];

        foreach ($contracts as $contract) {
            $saleDate = self::dateOf($contract->sale_date);

            if (($saleDate === null) || ($contract->construction_unit_id === null)) {
                continue;
            }

            foreach ([$saleDate, ...$positionDates] as $day) {
                $requests[$contract->construction_unit_id.'@'.$day] = ['unit_id' => (int) $contract->construction_unit_id, 'date' => CarbonImmutable::parse($day)];
            }
        }

        $unitIds = array_values(array_unique(array_column($requests, 'unit_id')));

        $units = ConstructionUnit::query()
            ->whereKey($unitIds)
            ->get(['id', 'construction_id', 'base_value', 'base_value_reference_date']);

        $tableValues = $unitValueResolver->forUnitDates($units, array_values($requests));

        $exchanges = ConstructionUnitExchange::query()
            ->whereIn('construction_unit_id', $unitIds)
            ->get(['id', 'construction_unit_id', 'contract_id', 'effective_from', 'ended_on'])
            ->groupBy(fn (ConstructionUnitExchange $exchange): int => (int) $exchange->construction_unit_id);

        /**
         * O model só decide se o contrato é de permuta -- o reconhecimento é o
         * da derivação, sobre o status e as datas convertidos. As datas cruas
         * continuam lidas da linha: o ano "0026" que se quer pegar é o texto
         * gravado.
         */
        $models = Contract::hydrate(array_map(fn (object $contract): array => (array) $contract, $contracts))->all();

        foreach ($contracts as $index => $contract) {
            $id = (int) $contract->id;
            $saleDate = self::dateOf($contract->sale_date);
            $saleCents = IntegerMoney::cents($contract->sale_value);
            $status = ContractStatus::tryFrom((string) $contract->status);

            if (($saleDate !== null) && ($saleCents !== null) && ($saleCents > 0) && ($status !== null)
                && ! ExchangeContractRecognizer::isOutsideTablePrice($status, $models[$index], $exchanges->get((int) $contract->construction_unit_id, collect()))) {
                $positions = [];

                if (in_array($status, [ContractStatus::Active, ContractStatus::Settled], true)) {
                    foreach ($positionDates as $day) {
                        $positions[$day] = $tableValues[$contract->construction_unit_id.'@'.$day] ?? null;
                    }
                }

                $this->collect('Contrato', $id, SpreadsheetPlausibility::saleAgainstReference(
                    $saleCents,
                    $saleDate,
                    $tableValues[$contract->construction_unit_id.'@'.$saleDate] ?? null,
                    $positions,
                ));
            }

            if (self::anyBeforeMinimumYear($contract->sale_date, $contract->cancellation_date)) {
                $this->record('Contrato: data anterior a '.SpreadsheetDate::MINIMUM_YEAR, $id);
            }
        }
    }

    /**
     * Cada linha do histórico de valores contra o valor que vigorava antes dela
     * na mesma unidade; vigências anteriores a 1990; e pares de vigências da
     * mesma unidade, com o mesmo valor, que são a mesma data com dia e mês
     * trocados -- a marca da leitura antiga de planilha.
     *
     * @param  list<int>  $constructionIds
     */
    private function checkUnitValues(array $constructionIds): void
    {
        $values = DB::table('construction_unit_values as value')
            ->join('construction_units as unit', 'unit.id', '=', 'value.construction_unit_id')
            ->whereIn('unit.construction_id', $constructionIds)
            ->orderBy('value.construction_unit_id')
            ->orderBy('value.effective_from')
            ->orderBy('value.id')
            ->select([
                'value.id',
                'value.construction_unit_id',
                'value.value',
                'value.effective_from',
                'unit.base_value',
                'unit.base_value_reference_date',
            ])
            ->cursor();

        $unitId = null;
        $history = [];

        foreach ($values as $value) {
            if ((int) $value->construction_unit_id !== $unitId) {
                $this->checkUnitHistory($history);

                $unitId = (int) $value->construction_unit_id;
                $history = [];
            }

            $history[] = $value;
        }

        $this->checkUnitHistory($history);
    }

    /**
     * @param  list<object>  $history  linhas de uma unidade, por vigência e id
     */
    private function checkUnitHistory(array $history): void
    {
        if ($history === []) {
            return;
        }

        usort($history, fn (object $a, object $b): int => [self::dateOf($a->effective_from), (int) $a->id]
            <=> [self::dateOf($b->effective_from), (int) $b->id]);

        $baseCents = IntegerMoney::cents($history[0]->base_value);
        $baseDate = self::dateOf($history[0]->base_value_reference_date);
        $previous = null;
        $seen = [];

        foreach ($history as $value) {
            $id = (int) $value->id;
            $date = (string) self::dateOf($value->effective_from);
            $cents = IntegerMoney::cents($value->value);

            $reference = $previous ?? ((($baseDate !== null) && ($baseDate <= $date)) ? $baseCents : null);

            if ($cents !== null) {
                $this->collect('Valor de unidade', $id, SpreadsheetPlausibility::unitValue($cents, $reference));
            }

            if (self::anyBeforeMinimumYear($date)) {
                $this->record('Valor de unidade: vigência anterior a '.SpreadsheetDate::MINIMUM_YEAR, $id);
            }

            $swapped = self::swapped($date);

            if (($swapped !== null) && isset($seen[$swapped.'|'.$cents])) {
                $this->record('Valor de unidade: vigência com dia e mês trocados', $seen[$swapped.'|'.$cents]);
            }

            $seen[$date.'|'.$cents] ??= $id;
            $previous = $cents;
        }
    }

    /**
     * Valor base de cada unidade contra a mediana do empreendimento.
     *
     * @param  list<int>  $constructionIds
     */
    private function checkUnitBaseValues(array $constructionIds): void
    {
        foreach (array_chunk($constructionIds, self::CHUNK_SIZE) as $chunk) {
            $byConstruction = [];

            DB::table('construction_units')
                ->whereIn('construction_id', $chunk)
                ->whereNotNull('base_value')
                ->orderBy('id')
                ->select(['id', 'construction_id', 'base_value', 'base_value_reference_date'])
                ->get()
                ->each(function (object $unit) use (&$byConstruction): void {
                    $byConstruction[(int) $unit->construction_id][] = $unit;
                });

            foreach ($byConstruction as $units) {
                $median = SpreadsheetPlausibility::median(array_values(array_filter(array_map(
                    fn (object $unit): ?int => IntegerMoney::cents($unit->base_value),
                    $units,
                ), fn (?int $cents): bool => $cents !== null)));

                foreach ($units as $unit) {
                    $cents = IntegerMoney::cents($unit->base_value);

                    if ($cents !== null) {
                        $this->collect('Unidade (valor base)', (int) $unit->id, SpreadsheetPlausibility::unitBaseValue($cents, $median));
                    }

                    if (self::anyBeforeMinimumYear($unit->base_value_reference_date)) {
                        $this->record('Unidade: referência do valor base anterior a '.SpreadsheetDate::MINIMUM_YEAR, (int) $unit->id);
                    }
                }
            }
        }
    }

    private function collect(string $subject, int $id, PlausibilityVerdict $verdict): void
    {
        if ($verdict->error !== null) {
            $this->record($subject.': erro ('.self::errorRule($verdict->error).')', $id);
        }

        foreach ($verdict->warnings as $warning) {
            $this->record($subject.': aviso ('.self::warningRule($warning['code']).')', $id);
        }
    }

    private function record(string $rule, int $id): void
    {
        $this->findings[$rule][$id] = $id;
    }

    /**
     * O nome da regra de um erro, sem os valores da mensagem.
     */
    private static function errorRule(string $error): string
    {
        return match (true) {
            str_starts_with($error, 'Valor previsto') => 'previsto acima do dobro da venda',
            str_starts_with($error, 'Valor pago') => 'pago acima do dobro da venda',
            str_starts_with($error, 'Valor da venda') => 'venda a dez vezes ou mais da tabela, ou a um décimo ou menos',
            str_starts_with($error, 'Valor atualizado') => 'valor a dez vezes ou mais do anterior, ou a um décimo ou menos',
            str_starts_with($error, 'Valor base') => 'valor base além de cem vezes a mediana',
            default => 'fora de escala',
        };
    }

    private static function warningRule(ImportRowWarningCode $code): string
    {
        return match ($code) {
            ImportRowWarningCode::UnitValueFarFromCurrent => 'valor distante do anterior',
            default => mb_strtolower($code->label()),
        };
    }

    private static function anyBeforeMinimumYear(mixed ...$dates): bool
    {
        foreach ($dates as $date) {
            $day = self::dateOf($date);

            if (($day !== null) && ((int) substr($day, 0, 4) < SpreadsheetDate::MINIMUM_YEAR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A mesma data com dia e mês trocados, quando os dois cabem num mês.
     */
    private static function swapped(string $date): ?string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        if (($day > 12) || ($month > 12) || ($day === $month)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $day, $month);
    }

    private static function dateOf(mixed $value): ?string
    {
        return blank($value) ? null : substr((string) $value, 0, 10);
    }
}
