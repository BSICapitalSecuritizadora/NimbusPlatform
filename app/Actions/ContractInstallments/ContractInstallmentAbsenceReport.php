<?php

declare(strict_types=1);

namespace App\Actions\ContractInstallments;

use App\Models\ContractInstallment;
use App\Support\Money\IntegerMoney;

/**
 * Parcelas cadastradas dos contratos presentes na planilha que não vieram nela.
 *
 * A fonte nunca preenche o cancelamento: quando uma renegociação renumera o
 * cronograma, a parcela antiga simplesmente deixa de vir. Sem este relatório ela
 * ficava viva para sempre, segurando a quitação do contrato, sem nenhum sinal.
 *
 * Só os contratos que aparecem na planilha entram -- uma planilha pode vir por
 * partes, e um contrato inteiro ausente não diz nada sobre as parcelas dele.
 * Nada é alterado aqui: o cancelamento das ausentes em aberto só acontece por
 * opção explícita no Confirmar.
 *
 * Separa as ausentes em:
 *
 * - em aberto: sem pagamento e sem cancelamento -- as únicas que a opção cancela;
 * - pagas: o pago mais o desconto registrado cobrem o previsto, a mesma regra de
 *   "paga" da parcela e da quitação do Quadro -- listadas, nunca tocadas;
 * - parcialmente pagas: têm recebimento, mas abaixo do previsto e sem desconto
 *   registrado -- grupo próprio, fora do cancelamento por opção (têm
 *   recebimento). A parcela antiga de uma renegociação costuma ficar assim, e
 *   segura o contrato como financiado no Quadro: a saída é registrar o desconto
 *   concedido, se houve, ou cancelar a parcela pela edição;
 * - já canceladas: só contadas.
 *
 * Guarda uma amostra limitada para a tela, as contagens, os ids das em aberto
 * (para o cancelamento) e um digest das em aberto e das pagas, que entra no
 * digest da conferência: se a posição delas muda entre a conferência e o
 * Confirmar, a conferência fica desatualizada.
 */
final class ContractInstallmentAbsenceReport
{
    public const SAMPLE_LIMIT = 200;

    public const SITUATION_OPEN = 'em_aberto';

    public const SITUATION_PAID = 'paga';

    public const SITUATION_PARTIAL = 'parcialmente_paga';

    private const CONTRACT_CHUNK = 500;

    private int $openCount = 0;

    private int $paidCount = 0;

    private int $partialCount = 0;

    private int $cancelledCount = 0;

    /**
     * @var list<int>
     */
    private array $openIds = [];

    /**
     * Empreendimentos das ausentes em aberto: é contra as competências deles que
     * a data do cancelamento escolhida é avisada.
     *
     * @var array<int, true>
     */
    private array $openConstructionIds = [];

    /**
     * @var list<array{contract: string, number: string, due_date: string, expected_value: string|null, situation: string, paid_value: string|null, discount_value: string|null, payment_date: string|null}>
     */
    private array $sample = [];

    private string $digest = '';

    private function __construct() {}

    public static function none(): self
    {
        $report = new self;
        $report->digest = hash('sha256', '');

        return $report;
    }

    /**
     * Lê, por cursor e sem hidratar models, as parcelas vivas dos contratos
     * presentes e separa as que a planilha não mencionou.
     *
     * @param  array<int, string>  $presentContracts  contrato => código cadastrado
     * @param  array<int, array<string, int|true>>  $mentioned  contrato => número normalizado => linha
     * @param  array<int, int>  $contractConstructions  contrato => empreendimento
     */
    public static function collect(array $presentContracts, array $mentioned, array $contractConstructions = []): self
    {
        $report = new self;
        $context = hash_init('sha256');

        foreach (array_chunk(array_keys($presentContracts), self::CONTRACT_CHUNK) as $contractIds) {
            $installments = ContractInstallment::query()
                ->toBase()
                ->whereIn('contract_id', $contractIds)
                ->orderBy('contract_id')
                ->orderBy('due_date')
                ->orderBy('id')
                ->select(StoredInstallment::COLUMNS)
                ->cursor();

            foreach ($installments as $row) {
                $installment = StoredInstallment::fromRow($row);

                if (isset($mentioned[$installment->contractId][$installment->numberNormalized])) {
                    continue;
                }

                $report->register(
                    $installment,
                    $presentContracts[$installment->contractId] ?? '',
                    $contractConstructions[$installment->contractId] ?? null,
                    $context,
                );
            }
        }

        $report->digest = hash_final($context);

        return $report;
    }

    public function openCount(): int
    {
        return $this->openCount;
    }

    public function paidCount(): int
    {
        return $this->paidCount;
    }

    public function cancelledCount(): int
    {
        return $this->cancelledCount;
    }

    public function partialCount(): int
    {
        return $this->partialCount;
    }

    /**
     * Ausentes que contam para a importação: em aberto, pagas e parcialmente
     * pagas. As já canceladas ficam de fora -- não há nada a decidir sobre elas.
     */
    public function absentCount(): int
    {
        return $this->openCount + $this->paidCount + $this->partialCount;
    }

    /**
     * @return list<int>
     */
    public function openIds(): array
    {
        return $this->openIds;
    }

    /**
     * Empreendimentos das ausentes em aberto, em ordem de id.
     *
     * @return list<int>
     */
    public function openConstructionIds(): array
    {
        $ids = array_keys($this->openConstructionIds);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<array{contract: string, number: string, due_date: string, expected_value: string|null, situation: string, paid_value: string|null, discount_value: string|null, payment_date: string|null}>
     */
    public function sample(): array
    {
        return $this->sample;
    }

    /**
     * SHA-256 das ausentes em aberto, pagas e parcialmente pagas, na ordem do
     * cadastro.
     */
    public function digest(): string
    {
        return $this->digest;
    }

    private function register(StoredInstallment $installment, string $contractCode, ?int $constructionId, \HashContext $context): void
    {
        if ($installment->cancellationDate !== null) {
            $this->cancelledCount++;

            return;
        }

        $situation = $this->situationOf($installment);

        if ($situation === self::SITUATION_OPEN) {
            $this->openCount++;
            $this->openIds[] = $installment->id;

            if ($constructionId !== null) {
                $this->openConstructionIds[$constructionId] = true;
            }
        } elseif ($situation === self::SITUATION_PARTIAL) {
            $this->partialCount++;
        } else {
            $this->paidCount++;
        }

        hash_update($context, implode('|', [
            $installment->id,
            $situation,
            $installment->dueDate,
            $installment->expectedCents ?? '',
            $installment->paymentDate ?? '',
            $installment->paidCents ?? '',
            $installment->discountCents ?? '',
        ])."\n");

        if (count($this->sample) < self::SAMPLE_LIMIT) {
            $this->sample[] = [
                'contract' => $contractCode,
                'number' => $installment->number,
                'due_date' => $installment->dueDate,
                'expected_value' => $installment->expectedCents === null ? null : IntegerMoney::decimalString($installment->expectedCents),
                'situation' => $situation,
                'paid_value' => $installment->paidCents === null ? null : IntegerMoney::decimalString($installment->paidCents),
                'discount_value' => $installment->discountCents === null ? null : IntegerMoney::decimalString($installment->discountCents),
                'payment_date' => $installment->paymentDate,
            ];
        }
    }

    /**
     * A situação de uma ausente viva. Um só lugar decide o que é "em aberto"
     * -- é dele que depende o que a opção de cancelamento pode tocar -- e o que é
     * "paga": o pago mais o desconto registrado cobrindo o previsto, como na
     * quitação do Quadro.
     */
    private function situationOf(StoredInstallment $installment): string
    {
        if ($installment->paymentDate === null) {
            return self::SITUATION_OPEN;
        }

        $covered = ($installment->paidCents ?? 0) + ($installment->discountCents ?? 0);

        return ($installment->paidCents !== null) && ($covered >= ($installment->expectedCents ?? 0))
            ? self::SITUATION_PAID
            : self::SITUATION_PARTIAL;
    }
}
