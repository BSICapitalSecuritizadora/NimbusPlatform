<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ContractScheduleFacts;
use App\DTOs\SalesBoards\ContractScheduleResolution;
use App\DTOs\SalesBoards\ContractSettlementSummary;
use App\Enums\ContractSettlementState;
use App\Models\ContractInstallment;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardPlausibility;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Se cada contrato estava quitado numa data, decidido pelo cronograma.
 *
 * Nunca lê `Contract.status`. O status é a foto de hoje; um contrato hoje
 * marcado como quitado ainda devia parcelas em março, e responder "quitado" para
 * março seria antedatar a quitação.
 *
 * Regras fechadas:
 *
 * - parcela **válida** em D: não apagada e (sem cancelamento ou cancelada depois
 *   de D). Uma parcela cancelada depois de D ainda existia em D;
 * - parcela **paga** em D: `payment_date <= D` e `paid_value + discount_value >=
 *   expected_value`, em centavos. Juros e multa empurram o recebimento acima do
 *   previsto, e isso continua sendo quitação. O desconto só conta quando está
 *   registrado na parcela (`discount_value`), e ele só existe junto com a baixa:
 *   pagamento abaixo do previsto sem desconto registrado é pagamento parcial, e
 *   a parcela continua em aberto -- a mesma regra de
 *   {@see ContractInstallment::getStatusAttribute()};
 * - **zero parcelas válidas não é quitação**. É {@see ContractSettlementState::Undetermined}:
 *   um contrato cujo cronograma o Nimbus não conhece não pode ser declarado
 *   quitado por vacuidade;
 * - **distrato posterior a D.** O distrato costuma vir precedido do cancelamento
 *   das parcelas em aberto, às vezes semanas antes. Sem esta regra, um contrato
 *   distratado em 05/07 com as parcelas em aberto canceladas em 25/06 sairia em
 *   junho "quitado" (só sobraram as pagas) e com uma "Quitação do mês" que não
 *   houve. Quando o contrato tem distrato numa data posterior a D, e só o
 *   cancelamento o quitaria ou esvaziaria em D, as parcelas canceladas até D
 *   voltam a contar -- válidas e em aberto, ou pagas se tiveram pagamento
 *   integral até D. Em qualquer outro caso nada muda: o contrato que já estava
 *   em aberto mantém a contagem, e a renegociação de contrato vivo (sem
 *   distrato) continua quitando pelo cronograma novo. O mapa de distratos vem
 *   de quem chama, dos contratos já carregados, sem consulta a mais.
 *
 * A soma das parcelas não precisa bater com `sale_value` -- isso é indicador de
 * conferência do domínio, não regra de quitação.
 *
 * API de lote: uma consulta para todos os contratos, resolvidos em memória.
 *
 * As parcelas passam uma vez, como linhas cruas, e não ficam retidas: cada uma
 * só incrementa os acumuladores do seu contrato em cada data pedida. Hidratar
 * cada parcela como model -- com casts de data e de decimal -- custava ~2 KB e
 * dezenas de microssegundos por parcela, e uma obra madura tem dezenas de
 * milhares delas; o resumo só precisa das contagens e de alguns máximos.
 */
class ContractSettlementResolver
{
    /**
     * @param  list<int>  $contractIds
     * @param  array<int, CarbonInterface|string|null>  $distratoDates  contrato => data do distrato, dos contratos que têm
     * @return array<int, ContractSettlementSummary> indexado por `contract_id`
     */
    public function resolveForContracts(array $contractIds, CarbonInterface $date, array $distratoDates): array
    {
        $day = $this->normalizeDate($date);

        return $this->resolveForContractsAtDates($contractIds, [$day], $distratoDates)[$day->toDateString()] ?? [];
    }

    /**
     * As mesmas contas em várias datas, com um único carregamento.
     *
     * É o que permite derivar a quitação da competência: o estado no fim do mês
     * e no dia anterior ao início dele saem da mesma leitura.
     *
     * @param  list<int>  $contractIds
     * @param  list<CarbonInterface>  $dates
     * @param  array<int, CarbonInterface|string|null>  $distratoDates  contrato => data do distrato, dos contratos que têm
     * @return array<string, array<int, ContractSettlementSummary>> `Y-m-d` => contrato => resumo
     */
    public function resolveForContractsAtDates(array $contractIds, array $dates, array $distratoDates): array
    {
        return $this->resolveScheduleAtDates($contractIds, $dates, $distratoDates)->summaries;
    }

    /**
     * A quitação em cada data e os fatos do cronograma, numa consulta e numa
     * passada.
     *
     * Por data e contrato: válidas, pagas, com pagamento até a data, a diferença
     * das pagas abaixo do previsto, a maior parcela prevista e a maior paga, e a
     * primeira paga cem vezes acima do previsto. Por contrato, sem data: as
     * datas anteriores a 1990 (pagamento e cancelamento) e os pagamentos depois
     * de hoje no calendário de negócio.
     *
     * @param  list<int>  $contractIds
     * @param  list<CarbonInterface>  $dates
     * @param  array<int, CarbonInterface|string|null>  $distratoDates  contrato => data do distrato, dos contratos que têm
     */
    public function resolveScheduleAtDates(array $contractIds, array $dates, array $distratoDates): ContractScheduleResolution
    {
        $contractIds = array_values(array_unique(array_map('intval', $contractIds)));
        $days = collect($dates)
            ->map(fn (CarbonInterface $date): CarbonImmutable => $this->normalizeDate($date))
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();

        if (($contractIds === []) || $days->isEmpty()) {
            return new ContractScheduleResolution(
                summaries: $days->mapWithKeys(fn (CarbonImmutable $date): array => [$date->toDateString() => []])->all(),
                facts: [],
            );
        }

        $dayStrings = $days->map(fn (CarbonImmutable $day): string => $day->toDateString())->values()->all();

        [$valid, $cancelled, $facts] = $this->tallyInstallments($contractIds, $dayStrings);

        $distratos = self::distratoDays($distratoDates);
        $resolved = [];

        foreach ($days as $index => $day) {
            $dayString = $day->toDateString();
            $summaries = [];

            foreach ($contractIds as $contractId) {
                $tally = $valid[$index][$contractId] ?? self::emptyTally();
                $cancelledByDay = $cancelled[$index][$contractId] ?? null;

                if (($cancelledByDay !== null)
                    && isset($distratos[$contractId])
                    && ($distratos[$contractId] > $dayString)
                    && (($tally['valid'] === 0) || ($tally['paid'] === $tally['valid']))) {
                    $tally = self::merge($tally, $cancelledByDay);
                }

                $summaries[$contractId] = $this->summarise($contractId, $tally, $day);
            }

            $resolved[$dayString] = $summaries;
        }

        return new ContractScheduleResolution(
            summaries: $resolved,
            facts: array_map(
                static fn (array $fact): ContractScheduleFacts => new ContractScheduleFacts(...$fact),
                $facts,
            ),
        );
    }

    /**
     * @param  array{valid: int, paid: int, withPayment: int, shortfall: int, maxExpected: int|null, maxExpectedNumber: string|null, maxExpectedId: int, maxPaid: int|null, maxPaidNumber: string|null, maxPaidId: int, farAboveNumber: string|null, farAboveId: int}  $tally
     */
    private function summarise(int $contractId, array $tally, CarbonImmutable $date): ContractSettlementSummary
    {
        $validInstallments = $tally['valid'];

        return new ContractSettlementSummary(
            contractId: $contractId,
            positionDate: $date,
            state: match (true) {
                $validInstallments === 0 => ContractSettlementState::Undetermined,
                $tally['paid'] === $validInstallments => ContractSettlementState::Settled,
                default => ContractSettlementState::Outstanding,
            },
            validInstallments: $validInstallments,
            paidInstallments: $validInstallments === 0 ? 0 : $tally['paid'],
            installmentsWithPayment: $tally['withPayment'],
            shortfallCents: $tally['shortfall'],
            maxExpectedCents: $tally['maxExpected'],
            maxExpectedNumber: $tally['maxExpectedNumber'],
            maxPaidCents: $tally['maxPaid'],
            maxPaidNumber: $tally['maxPaidNumber'],
            paidFarAboveExpectedNumber: $tally['farAboveNumber'],
        );
    }

    /**
     * Os acumuladores de cada contrato em cada data, numa consulta e numa
     * passada, e os fatos sem data de cada contrato.
     *
     * Sem filtro de data no SQL de propósito: as fronteiras de validade e de
     * pagamento são decididas em PHP, o que evita a divergência de comparação de
     * datas entre SQLite e MySQL e permite responder várias datas com uma
     * leitura só. O escopo já está limitado pelos contratos do empreendimento.
     *
     * As canceladas até cada data são acumuladas à parte: só entram na conta
     * quando o distrato posterior as devolve (ver a regra no docblock da
     * classe).
     *
     * Parcelas apagadas ficam de fora pelo soft delete do próprio model, que
     * `toBase()` preserva. Os empates de "maior" e "primeira" ficam com o menor
     * `id`, para a resposta não depender da ordem em que o banco devolve as
     * linhas.
     *
     * @param  list<int>  $contractIds
     * @param  list<string>  $days  `Y-m-d`, na ordem das datas pedidas
     * @return array{0: array<int, array<int, array<string, mixed>>>, 1: array<int, array<int, array<string, mixed>>>, 2: array<int, array<string, mixed>>} válidas e canceladas por índice da data e contrato, e os fatos por contrato
     */
    private function tallyInstallments(array $contractIds, array $days): array
    {
        $valid = array_fill_keys(array_keys($days), []);
        $cancelled = $valid;
        $facts = [];
        $today = BusinessTime::dateString();

        $installments = ContractInstallment::query()
            ->toBase()
            ->whereIn('contract_id', $contractIds)
            ->select(['id', 'contract_id', 'number', 'expected_value', 'paid_value', 'discount_value', 'payment_date', 'cancellation_date'])
            ->cursor();

        foreach ($installments as $installment) {
            $id = (int) $installment->id;
            $contractId = (int) $installment->contract_id;
            $number = (string) $installment->number;
            $cancellationDate = self::dateString($installment->cancellation_date);
            $paymentDate = self::dateString($installment->payment_date);
            $expected = IntegerMoney::cents($installment->expected_value) ?? 0;
            $paid = IntegerMoney::cents($installment->paid_value);
            $discount = IntegerMoney::cents($installment->discount_value) ?? 0;
            $hasPayment = ($paymentDate !== null) && ($paid !== null);
            $fullyPaid = $hasPayment && (($paid + $discount) >= $expected);
            $farAbove = $hasPayment && SalesBoardPlausibility::isAtypicalPayment($paid, $expected);

            self::recordFacts($facts, $contractId, $id, $number, $paymentDate, $cancellationDate, $today);

            foreach ($days as $index => $day) {
                $paidByDay = $hasPayment && ($paymentDate <= $day);

                if (self::isValidOn($cancellationDate, $day)) {
                    $tally = &$valid[$index][$contractId];
                } else {
                    $tally = &$cancelled[$index][$contractId];
                }

                $tally ??= self::emptyTally();
                $tally['valid']++;

                if (($expected > ($tally['maxExpected'] ?? -1)) || (($expected === $tally['maxExpected']) && ($id < $tally['maxExpectedId']))) {
                    $tally['maxExpected'] = $expected;
                    $tally['maxExpectedNumber'] = $number;
                    $tally['maxExpectedId'] = $id;
                }

                if ($paidByDay) {
                    $tally['withPayment']++;

                    if ($fullyPaid) {
                        $tally['paid']++;
                    } else {
                        $tally['shortfall'] += max(0, $expected - $paid - $discount);
                    }

                    if (($paid > ($tally['maxPaid'] ?? -1)) || (($paid === $tally['maxPaid']) && ($id < $tally['maxPaidId']))) {
                        $tally['maxPaid'] = $paid;
                        $tally['maxPaidNumber'] = $number;
                        $tally['maxPaidId'] = $id;
                    }

                    if ($farAbove && (($tally['farAboveNumber'] === null) || ($id < $tally['farAboveId']))) {
                        $tally['farAboveNumber'] = $number;
                        $tally['farAboveId'] = $id;
                    }
                }

                unset($tally);
            }
        }

        return [$valid, $cancelled, $facts];
    }

    /**
     * Pagamento e cancelamento anteriores a 1990, e pagamento depois de hoje: o
     * mais antigo de cada um, com o número da parcela.
     *
     * @param  array<int, array<string, mixed>>  $facts
     */
    private static function recordFacts(
        array &$facts,
        int $contractId,
        int $id,
        string $number,
        ?string $paymentDate,
        ?string $cancellationDate,
        string $today,
    ): void {
        foreach (['payment' => $paymentDate, 'cancellation' => $cancellationDate] as $field => $date) {
            if (! SalesBoardPlausibility::isBeforeMinimumYear($date)) {
                continue;
            }

            $fact = &$facts[$contractId];
            $fact ??= ['contractId' => $contractId];
            $fact['datesBefore1990'] = ($fact['datesBefore1990'] ?? 0) + 1;

            if (! isset($fact['firstDateBefore1990']) || ($date < $fact['firstDateBefore1990'])) {
                $fact['firstDateBefore1990'] = $date;
                $fact['firstDateBefore1990Number'] = $number;
                $fact['firstDateBefore1990Field'] = $field;
            }

            unset($fact);
        }

        if (($paymentDate === null) || ($paymentDate <= $today)) {
            return;
        }

        $fact = &$facts[$contractId];
        $fact ??= ['contractId' => $contractId];
        $fact['paymentsAfterToday'] = ($fact['paymentsAfterToday'] ?? 0) + 1;

        if (! isset($fact['firstPaymentAfterToday']) || ($paymentDate < $fact['firstPaymentAfterToday'])) {
            $fact['firstPaymentAfterToday'] = $paymentDate;
            $fact['firstPaymentAfterTodayNumber'] = $number;
        }

        unset($fact);
    }

    /**
     * @return array{valid: int, paid: int, withPayment: int, shortfall: int, maxExpected: int|null, maxExpectedNumber: string|null, maxExpectedId: int, maxPaid: int|null, maxPaidNumber: string|null, maxPaidId: int, farAboveNumber: string|null, farAboveId: int}
     */
    private static function emptyTally(): array
    {
        return [
            'valid' => 0,
            'paid' => 0,
            'withPayment' => 0,
            'shortfall' => 0,
            'maxExpected' => null,
            'maxExpectedNumber' => null,
            'maxExpectedId' => PHP_INT_MAX,
            'maxPaid' => null,
            'maxPaidNumber' => null,
            'maxPaidId' => PHP_INT_MAX,
            'farAboveNumber' => null,
            'farAboveId' => PHP_INT_MAX,
        ];
    }

    /**
     * As canceladas até a data somadas às válidas, como se o cancelamento ainda
     * não tivesse acontecido -- é o que a regra do distrato posterior pede.
     *
     * @param  array<string, mixed>  $tally
     * @param  array<string, mixed>  $other
     * @return array<string, mixed>
     */
    private static function merge(array $tally, array $other): array
    {
        $merged = [
            ...$tally,
            'valid' => $tally['valid'] + $other['valid'],
            'paid' => $tally['paid'] + $other['paid'],
            'withPayment' => $tally['withPayment'] + $other['withPayment'],
            'shortfall' => $tally['shortfall'] + $other['shortfall'],
        ];

        foreach ([['maxExpected', 'maxExpectedNumber', 'maxExpectedId'], ['maxPaid', 'maxPaidNumber', 'maxPaidId']] as [$value, $number, $id]) {
            if (($other[$value] !== null)
                && (($merged[$value] === null) || ($other[$value] > $merged[$value]) || (($other[$value] === $merged[$value]) && ($other[$id] < $merged[$id])))) {
                $merged[$value] = $other[$value];
                $merged[$number] = $other[$number];
                $merged[$id] = $other[$id];
            }
        }

        if (($other['farAboveNumber'] !== null) && (($merged['farAboveNumber'] === null) || ($other['farAboveId'] < $merged['farAboveId']))) {
            $merged['farAboveNumber'] = $other['farAboveNumber'];
            $merged['farAboveId'] = $other['farAboveId'];
        }

        return $merged;
    }

    /**
     * @param  array<int, CarbonInterface|string|null>  $distratoDates
     * @return array<int, string> contrato => `Y-m-d`
     */
    private static function distratoDays(array $distratoDates): array
    {
        $days = [];

        foreach ($distratoDates as $contractId => $date) {
            $day = $date instanceof CarbonInterface ? $date->toDateString() : self::dateString($date);

            if ($day !== null) {
                $days[(int) $contractId] = $day;
            }
        }

        return $days;
    }

    /**
     * Existia como obrigação na data.
     */
    private static function isValidOn(?string $cancellationDate, string $day): bool
    {
        return ($cancellationDate === null) || ($cancellationDate > $day);
    }

    /**
     * A data crua da coluna, reduzida a `Y-m-d`.
     *
     * O MySQL devolve `2026-07-10`; o SQLite devolve `2026-07-10 00:00:00`
     * quando a linha foi gravada pelo Eloquent e `2026-07-10` quando veio de um
     * `insert()` em lote. Os dez primeiros caracteres são o dia nos três casos,
     * e é o dia que a regra compara.
     */
    private static function dateString(mixed $value): ?string
    {
        if (($value === null) || ($value === '')) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function normalizeDate(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString());
    }
}
