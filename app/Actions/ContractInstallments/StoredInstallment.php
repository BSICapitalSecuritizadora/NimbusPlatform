<?php

declare(strict_types=1);

namespace App\Actions\ContractInstallments;

use App\Models\ContractInstallment;
use App\Support\Money\IntegerMoney;
use App\Support\Reconciliation\ValueComparator;

/**
 * Uma parcela cadastrada como a conferência precisa dela: datas `Y-m-d` e
 * dinheiro em centavos, lida da linha crua do banco.
 *
 * Hidratar o model custava cerca de 2 KB por parcela, e uma carteira mensal tem
 * centenas de milhares delas -- a maioria idêntica ao que a planilha traz. Daqui
 * sai a comparação rápida ({@see self::isIdenticalTo()}); só o que diverge passa
 * pelo {@see ContractInstallmentReconciler}, que continua sendo a autoridade.
 *
 * Campo novo da parcela entra junto aqui -- em {@see self::COLUMNS}, no
 * construtor e em {@see self::isIdenticalTo()} -- e no reconciliador: a
 * comparação rápida nunca pode ignorar um campo que o reconciliador compara. O
 * desconto concedido entrou assim.
 */
final readonly class StoredInstallment
{
    /**
     * Colunas lidas do banco, além das de identidade.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'id',
        'contract_id',
        'number',
        'number_normalized',
        'due_date',
        'expected_value',
        'payment_date',
        'paid_value',
        'discount_value',
        'cancellation_date',
    ];

    public function __construct(
        public int $id,
        public int $contractId,
        public string $number,
        public string $numberNormalized,
        public string $dueDate,
        public ?int $expectedCents,
        public ?string $paymentDate,
        public ?int $paidCents,
        public ?string $cancellationDate,
        public ?int $discountCents = null,
    ) {}

    /**
     * A partir da linha crua de `toBase()`. As datas vêm como texto do banco --
     * "2026-01-10" ou "2026-01-10 00:00:00", conforme quem gravou --, então a
     * data civil é o prefixo; o dinheiro vem como decimal em texto.
     */
    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            contractId: (int) $row->contract_id,
            number: (string) $row->number,
            numberNormalized: (string) $row->number_normalized,
            dueDate: (string) self::dateOf($row->due_date),
            expectedCents: IntegerMoney::cents($row->expected_value),
            paymentDate: self::dateOf($row->payment_date),
            paidCents: IntegerMoney::cents($row->paid_value),
            cancellationDate: self::dateOf($row->cancellation_date),
            discountCents: IntegerMoney::cents($row->discount_value ?? null),
        );
    }

    public static function fromModel(ContractInstallment $installment): self
    {
        return new self(
            id: (int) $installment->getKey(),
            contractId: (int) $installment->contract_id,
            number: (string) $installment->number,
            numberNormalized: (string) $installment->number_normalized,
            dueDate: (string) ValueComparator::date($installment->due_date),
            expectedCents: IntegerMoney::cents($installment->expected_value),
            paymentDate: ValueComparator::date($installment->payment_date),
            paidCents: IntegerMoney::cents($installment->paid_value),
            cancellationDate: ValueComparator::date($installment->cancellation_date),
            discountCents: IntegerMoney::cents($installment->discount_value),
        );
    }

    /**
     * Se a linha da planilha é exatamente o que está cadastrado -- o caso de
     * quase toda linha de um arquivo mensal, decidido aqui sem passar pelo
     * reconciliador.
     *
     * O cancelamento vazio no arquivo conta como igual, como no reconciliador: a
     * fonte nunca preenche a coluna, então a célula vazia não diz nada sobre o
     * cancelamento cadastrado. O mesmo não vale para o recebimento: um
     * recebimento cadastrado que a planilha não traz é divergência informativa,
     * e por isso fica para o reconciliador. O desconto segue o recebimento
     * quando a planilha traz a coluna; arquivo sem a coluna não diz nada sobre
     * ele (`$discountInFile` falso), e o desconto cadastrado não entra na
     * comparação.
     */
    public function isIdenticalTo(
        string $dueDate,
        int $expectedCents,
        ?string $paymentDate,
        ?int $paidCents,
        ?string $cancellationDate,
        ?int $discountCents = null,
        bool $discountInFile = false,
    ): bool {
        return ($this->dueDate === $dueDate)
            && ($this->expectedCents === $expectedCents)
            && ($this->paymentDate === $paymentDate)
            && ($this->paidCents === $paidCents)
            && (($cancellationDate === null) || ($this->cancellationDate === $cancellationDate))
            && (! $discountInFile || ($this->discountCents === $discountCents));
    }

    private static function dateOf(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }
}
