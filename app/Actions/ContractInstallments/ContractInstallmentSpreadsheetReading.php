<?php

declare(strict_types=1);

namespace App\Actions\ContractInstallments;

use Generator;
use LogicException;

/**
 * Uma leitura em fluxo da planilha de parcelas: as linhas classificadas, uma a
 * uma, na ordem do arquivo, sem nunca reter o arquivo inteiro em memória.
 *
 * É de uso único. As linhas saem de um gerador que lê o XLSX por lotes; ler de
 * novo é abrir outra leitura. As ausências -- parcelas cadastradas dos contratos
 * presentes que a planilha não trouxe -- só existem depois da última linha,
 * porque só então se sabe o que veio.
 *
 * Quem dobra as linhas num resumo é {@see ContractInstallmentSpreadsheetAnalysis};
 * quem grava é {@see ImportContractInstallmentsFromSpreadsheet}, numa passada só.
 */
final class ContractInstallmentSpreadsheetReading
{
    private bool $started = false;

    private bool $consumed = false;

    private ?ContractInstallmentAbsenceReport $absences = null;

    /**
     * @param  list<string>  $fileErrors
     * @param  Generator<int, array<string, mixed>, mixed, ContractInstallmentAbsenceReport>|null  $rows
     */
    private function __construct(
        private readonly array $fileErrors,
        private readonly ?Generator $rows,
    ) {}

    /**
     * A leitura sobre o gerador do analisador, que entrega as linhas e, ao
     * terminar, devolve as ausências.
     *
     * @param  Generator<int, array<string, mixed>, mixed, ContractInstallmentAbsenceReport>  $rows
     */
    public static function streaming(Generator $rows): self
    {
        return new self([], $rows);
    }

    /**
     * @param  list<string>  $fileErrors
     */
    public static function withFileErrors(array $fileErrors): self
    {
        return new self($fileErrors, null);
    }

    /**
     * Problemas do arquivo como um todo (vazio, ilegível, sem as colunas
     * obrigatórias). Com eles, nenhuma linha é lida.
     *
     * @return list<string>
     */
    public function fileErrors(): array
    {
        return $this->fileErrors;
    }

    /**
     * As linhas classificadas, na ordem do arquivo. Cada linha carrega o
     * resultado, a mensagem, os avisos e, quando houver, a comparação com a
     * parcela cadastrada.
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws LogicException quando a leitura já foi iniciada
     */
    public function rows(): Generator
    {
        if ($this->started) {
            throw new LogicException('A leitura da planilha de parcelas é de uso único: abra outra para ler de novo.');
        }

        $this->started = true;

        if ($this->rows === null) {
            $this->absences = ContractInstallmentAbsenceReport::none();
            $this->consumed = true;

            return;
        }

        $this->absences = yield from $this->rows;
        $this->consumed = true;
    }

    /**
     * @throws LogicException quando as linhas ainda não foram todas lidas
     */
    public function absences(): ContractInstallmentAbsenceReport
    {
        if (! $this->consumed || ($this->absences === null)) {
            throw new LogicException('As parcelas ausentes só são conhecidas depois de lidas todas as linhas da planilha.');
        }

        return $this->absences;
    }
}
