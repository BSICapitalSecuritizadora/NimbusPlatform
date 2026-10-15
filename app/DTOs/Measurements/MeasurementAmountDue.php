<?php

namespace App\DTOs\Measurements;

use App\Services\MeasurementFinancialReconciliationService;
use Illuminate\Contracts\Support\Arrayable;

/**
 * O que ainda é devido numa medição, por empreendimento, e se a conta pode
 * ser feita -- a posição financeira autoritativa da etapa Pagamento.
 *
 * Dois estados que nunca se confundem:
 *
 * - **nada a pagar, demonstrado** ({@see self::isNothingDue()}): o registro
 *   verificável da Engenharia cobre empreendimentos com versão do plano e
 *   Fundo de Obra congelados (zero é Fundo válido), percentual aprovado
 *   legível e nenhum saldo a pagar -- e, na revisão, nenhum valor pago a maior
 *   à espera de decisão;
 * - **valor devido indeterminado**: falta o registro da Engenharia, o Fundo de
 *   Obra, o percentual, a versão do plano ou o contexto aprovado. Sem conta não
 *   existe "zero", e `undetermined` diz o porquê.
 *
 * Os empreendimentos não se compensam: a sobra de um não paga a falta de
 * outro. Valores em string decimal, escala 2, nunca float.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementAmountDue implements Arrayable
{
    /**
     * @param  list<MeasurementAmountDueLine>  $lines
     * @param  list<string>  $undetermined  por que o valor devido não pode ser determinado
     */
    public function __construct(
        public int $measurementId,
        public bool $isRevision,
        public array $lines,
        public array $undetermined,
        public ?string $engineeringSnapshotSha256,
    ) {}

    public function isDetermined(): bool
    {
        return $this->undetermined === [] && $this->lines !== [];
    }

    /**
     * @return list<MeasurementAmountDueLine>
     */
    public function payableLines(): array
    {
        return array_values(array_filter(
            $this->lines,
            fn (MeasurementAmountDueLine $line): bool => $line->isPayable(),
        ));
    }

    /**
     * @return list<MeasurementAmountDueLine>
     */
    public function overpaymentsAwaitingDecision(): array
    {
        return array_values(array_filter(
            $this->lines,
            fn (MeasurementAmountDueLine $line): bool => $line->hasUnacknowledgedOverpayment(),
        ));
    }

    /**
     * Nada a pagar, demonstrado: a posição está determinada, nenhum
     * empreendimento tem saldo a pagar e nenhum valor pago a maior espera
     * decisão. É o único estado em que a etapa Pagamento pode terminar sem
     * pagamento desta medição; valor devido indeterminado nunca é zero.
     */
    public function isNothingDue(): bool
    {
        return $this->isDetermined()
            && $this->payableLines() === []
            && $this->overpaymentsAwaitingDecision() === [];
    }

    /**
     * O saldo a pagar somado, ou `null` sem posição determinada.
     */
    public function totalRemainingAmount(): ?string
    {
        if (! $this->isDetermined()) {
            return null;
        }

        return array_reduce(
            $this->lines,
            fn (string $total, MeasurementAmountDueLine $line): string => bcadd($total, (string) $line->remainingAmount, 2),
            '0.00',
        );
    }

    /**
     * Por que a etapa Pagamento não pode terminar sem pagamento, como a pessoa
     * lê: a posição que não se determina, o valor devido de cada
     * empreendimento e o valor pago a maior à espera de decisão.
     *
     * @return list<string>
     */
    public function refusals(): array
    {
        return [
            ...$this->undetermined,
            ...($this->lines === [] && $this->undetermined === []
                ? ['O registro da Engenharia não tem empreendimento algum: o valor devido não pode ser determinado.']
                : []),
            ...array_map(
                fn (MeasurementAmountDueLine $line): string => sprintf(
                    '%s: valor devido %s.',
                    $line->label,
                    MeasurementFinancialReconciliationService::formatCurrency($line->remainingAmount),
                ),
                $this->payableLines(),
            ),
            ...array_map(
                fn (MeasurementAmountDueLine $line): string => sprintf(
                    '%s: valor pago a maior a reconhecer %s.',
                    $line->label,
                    MeasurementFinancialReconciliationService::formatCurrency($line->unacknowledgedOverpaymentAmount),
                ),
                $this->overpaymentsAwaitingDecision(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'measurement_id' => $this->measurementId,
            'is_revision' => $this->isRevision,
            'determined' => $this->isDetermined(),
            'nothing_due' => $this->isNothingDue(),
            'total_remaining_amount' => $this->totalRemainingAmount(),
            'engineering_snapshot_sha256' => $this->engineeringSnapshotSha256,
            'undetermined' => $this->undetermined,
            'lines' => array_map(
                fn (MeasurementAmountDueLine $line): array => $line->toArray(),
                $this->lines,
            ),
        ];
    }
}
