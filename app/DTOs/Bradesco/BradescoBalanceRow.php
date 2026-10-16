<?php

declare(strict_types=1);

namespace App\DTOs\Bradesco;

use App\DTOs\BaseDTO;

/**
 * One normalized lstLancamentosSaldos row.
 *
 * Carries the bank values verbatim (raw strings) plus their classified
 * reading. A blank valorLancamento or sinalSaldo yields null cents —
 * unknown, never zero — and a flag naming the gap. Rows are evidence;
 * no method here sums, nets or consolidates them.
 */
readonly class BradescoBalanceRow extends BaseDTO
{
    /**
     * @param  list<string>  $flags
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $section,
        public ?string $codigoProduto,
        public string $codigoProdutoRaw,
        public ?string $nomeProduto,
        public ?string $nomeProdutoResumido,
        public ?string $identificadorSaldo,
        public ?string $identificadorMeaning,
        public ?string $dataLancamentoDb2,
        public ?string $valorRaw,
        public ?string $sinalSaldo,
        public ?int $signedCents,
        public ?string $amountDecimal,
        public bool $isApplicationProduct,
        public array $flags,
        public array $raw,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'section' => $this->section,
            'codigo_produto' => $this->codigoProduto,
            'codigo_produto_raw' => $this->codigoProdutoRaw,
            'nome_produto' => $this->nomeProduto,
            'nome_produto_resumido' => $this->nomeProdutoResumido,
            'identificador_saldo' => $this->identificadorSaldo,
            'identificador_meaning' => $this->identificadorMeaning,
            'data_lancamento_db2' => $this->dataLancamentoDb2,
            'valor_raw' => $this->valorRaw,
            'sinal_saldo' => $this->sinalSaldo,
            'signed_cents' => $this->signedCents,
            'amount_decimal' => $this->amountDecimal,
            'is_application_product' => $this->isApplicationProduct,
            'flags' => $this->flags,
        ];
    }
}
