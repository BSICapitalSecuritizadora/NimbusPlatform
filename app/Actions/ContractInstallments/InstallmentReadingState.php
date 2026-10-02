<?php

declare(strict_types=1);

namespace App\Actions\ContractInstallments;

use App\Services\SalesBoards\RegisteredCompetenceIndex;

/**
 * O que uma leitura da planilha de parcelas acumula enquanto anda: os números
 * que cada contrato já trouxe e os contratos que a planilha alcançou.
 *
 * Vive só durante uma leitura ({@see AnalyzeContractInstallmentSpreadsheet::open()})
 * -- duas leituras nunca compartilham estado, mesmo vindas do mesmo analisador.
 *
 * Os números ficam num mapa só, `[contrato][número normalizado] => linha`, com o
 * sinal dizendo o que a linha foi: positiva para a primeira linha válida daquele
 * número (é contra ela que uma repetição é acusada), negativa para uma linha que
 * alcançou o contrato mas parou numa validação. As duas contam como "veio na
 * planilha" para as ausências; só a positiva conta para a repetição. Um mapa em
 * vez de dois porque, numa carteira mensal, ele tem uma entrada por linha.
 */
final class InstallmentReadingState
{
    /**
     * Contratos que alguma linha alcançou => código cadastrado.
     *
     * @var array<int, string>
     */
    public array $presentContracts = [];

    /**
     * Contratos que alguma linha alcançou => empreendimento. É dele que sai o
     * aviso de competência já registrada para o cancelamento das ausentes.
     *
     * @var array<int, int>
     */
    public array $contractConstructions = [];

    /**
     * @var array<int, array<string, int>>
     */
    private array $numbers = [];

    public function __construct(
        public readonly ?int $restrictToContractId,
        public readonly string $businessToday,
        public readonly RegisteredCompetenceIndex $registeredCompetences,
    ) {}

    public function reachContract(int $contractId, string $code, ?int $constructionId = null): void
    {
        $this->presentContracts[$contractId] ??= $code;

        if ($constructionId !== null) {
            $this->contractConstructions[$contractId] ??= $constructionId;
        }
    }

    /**
     * A linha alcançou o contrato com este número, válida ou não.
     */
    public function mention(int $contractId, ?string $number, int $line): void
    {
        if ($number === null) {
            return;
        }

        $this->numbers[$contractId][$number] ??= -$line;
    }

    /**
     * A linha da primeira ocorrência válida do número no contrato, ou `null`.
     */
    public function seenAt(int $contractId, ?string $number): ?int
    {
        $line = ($number === null) ? null : ($this->numbers[$contractId][$number] ?? null);

        return (($line !== null) && ($line > 0)) ? $line : null;
    }

    /**
     * Registra a primeira ocorrência válida do número no contrato.
     */
    public function see(int $contractId, ?string $number, int $line): void
    {
        if ($number === null) {
            return;
        }

        $this->numbers[$contractId][$number] = $line;
    }

    /**
     * Todo número que a planilha trouxe, por contrato.
     *
     * @return array<int, array<string, int>>
     */
    public function mentionedNumbers(): array
    {
        return $this->numbers;
    }
}
