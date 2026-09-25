<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardMovementType;
use App\Support\SalesBoards\CanonicalDigest;

/**
 * Os fatos materiais da fonte viva que uma competência realmente usa.
 *
 * "Material" é uma escolha, não uma coleta preguiçosa de tudo. Entra o que pode
 * mudar algum número, alguma classificação, algum movimento ou algum veredito de
 * conformidade -- e só isso. Telefone do comprador, e-mail, observação livre e
 * `due_date` de parcela ficam de fora porque não participam de nenhuma decisão
 * do Quadro: incluí-los faria o baseline "envelhecer" toda vez que alguém
 * corrigisse um contato, e um alerta que dispara sem motivo é um alerta que as
 * pessoas aprendem a ignorar.
 *
 * O recorte temporal é igualmente deliberado. Uma tabela de preço que passa a
 * valer depois da data da posição, ou uma política de desconto posterior a todas
 * as vendas da competência, não são fonte *daquele* mês -- não podem torná-lo
 * obsoleto. Por isso cada conjunto é carregado já limitado à vigência que a
 * competência alcança, em vez de filtrado depois.
 *
 * O recorte vale também para o que é datado dentro de um contrato. Pagamento e
 * distrato posteriores à data da posição não existiam nela, e a derivação os
 * ignora ao decidir ocupação e quitação; aqui eles entram como ausentes. Sem
 * isso, o pagamento de agosto importado antes da aprovação de julho marcaria
 * julho como "fonte alterada" todo mês -- e a justificativa que isso exige
 * viraria texto padrão justamente quando uma mudança real acontecesse.
 *
 * As linhas chegam aqui já canonizadas por {@see CanonicalDigest}, o que garante
 * que o resumo global e os resumos por unidade e por movimento sejam feitos
 * sobre exatamente a mesma representação dos mesmos fatos.
 *
 * As parcelas chegam já resumidas, uma por contrato. Nenhum dos três resumos
 * precisa de uma parcela isolada -- a unidade e a quitação olham o cronograma
 * do contrato inteiro --, e guardar uma linha por parcela fazia a observação de
 * uma obra madura ocupar tanta memória quanto a própria derivação.
 */
readonly class SalesBoardSourceObservation extends BaseDTO
{
    /**
     * @param  array<int, string>  $unitRows  linha canônica de cada unidade
     * @param  array<int, list<string>>  $valueRows  histórico vigente, por unidade
     * @param  array<int, list<string>>  $exchangeRows  permutas vigentes, por unidade
     * @param  array<int, string>  $contractRows  linha canônica de cada contrato
     * @param  array<int, list<int>>  $contractIdsByUnit  contratos de cada unidade
     * @param  array<int, string>  $installmentDigests  resumo do cronograma de cada contrato na data da posição
     * @param  array<int, int>  $unitIdByContract  unidade de cada contrato
     * @param  list<string>  $policyRows  políticas alcançadas pelas vendas da competência
     */
    public function __construct(
        public int $constructionId,
        public array $unitRows,
        public array $valueRows,
        public array $exchangeRows,
        public array $contractRows,
        public array $contractIdsByUnit,
        public array $installmentDigests,
        public array $unitIdByContract,
        public array $policyRows,
    ) {}

    /**
     * Resumo de toda a fonte material da competência.
     *
     * Calculado sobre o documento canônico inteiro, e não pela concatenação dos
     * resumos por linha: assim a inclusão ou a remoção de uma unidade, de um
     * contrato ou de uma parcela muda o hash mesmo quando nenhuma linha
     * remanescente mudou. A parcela entra pelo resumo do cronograma do
     * contrato, que muda com qualquer parcela incluída, removida ou alterada.
     */
    public function fingerprint(): string
    {
        $contractsWithSchedule = array_keys($this->installmentDigests);
        sort($contractsWithSchedule);

        return CanonicalDigest::of([
            'units' => $this->sorted($this->unitRows),
            'unit_values' => $this->flattened($this->valueRows),
            'exchanges' => $this->flattened($this->exchangeRows),
            'contracts' => $this->sorted($this->contractRows),
            'installment_schedules' => $this->scheduleRows($contractsWithSchedule),
            'policies' => $this->policyRows,
        ]);
    }

    /**
     * Resumo da fonte material atribuível a uma unidade.
     *
     * É o que transforma "alguma coisa mudou nesta competência" em "mudou a
     * fonte da unidade 101 do bloco 01". Sem essa atribuição, localizar a
     * alteração exigiria comparar tudo contra tudo.
     */
    public function fingerprintForUnit(int $unitId): string
    {
        $contractIds = $this->contractIdsByUnit[$unitId] ?? [];
        $contracts = [];

        foreach ($contractIds as $contractId) {
            if (isset($this->contractRows[$contractId])) {
                $contracts[] = $this->contractRows[$contractId];
            }
        }

        return CanonicalDigest::of([
            'unit' => isset($this->unitRows[$unitId]) ? [$this->unitRows[$unitId]] : [],
            'unit_values' => $this->valueRows[$unitId] ?? [],
            'exchanges' => $this->exchangeRows[$unitId] ?? [],
            'contracts' => $contracts,
            'installment_schedules' => $this->scheduleRows($contractIds),
        ]);
    }

    /**
     * Resumo da fonte material atribuível a um movimento.
     *
     * Cada tipo enxerga só o que o determina, e essa é a diferença entre um
     * alerta útil e um ruído. Um pagamento registrado não tem por que marcar um
     * distrato como alterado; uma política nova não tem por que marcar uma
     * quitação. A venda é a única que depende dos três -- contrato, tabela e
     * política -- porque é a única cujo veredito de conformidade sai deles.
     */
    public function fingerprintForMovement(SalesBoardMovementType $type, int $contractId): string
    {
        $contract = isset($this->contractRows[$contractId]) ? [$this->contractRows[$contractId]] : [];
        $unitId = $this->unitIdByContract[$contractId] ?? null;

        return match ($type) {
            SalesBoardMovementType::Sale => CanonicalDigest::of([
                'contract' => $contract,
                'unit_values' => $unitId === null ? [] : ($this->valueRows[$unitId] ?? []),
                'policies' => $this->policyRows,
            ]),
            SalesBoardMovementType::Settlement => CanonicalDigest::of([
                'contract' => $contract,
                'installment_schedules' => $this->scheduleRows([$contractId]),
            ]),
            SalesBoardMovementType::Cancellation => CanonicalDigest::of([
                'contract' => $contract,
            ]),
        };
    }

    /**
     * O cronograma de cada contrato como uma linha canônica, na ordem dada.
     *
     * Contrato sem parcela não produz linha -- a mesma ausência que existia
     * quando as parcelas entravam uma a uma.
     *
     * @param  list<int>  $contractIds
     * @return list<string>
     */
    private function scheduleRows(array $contractIds): array
    {
        $rows = [];

        foreach ($contractIds as $contractId) {
            if (isset($this->installmentDigests[$contractId])) {
                $rows[] = CanonicalDigest::row([$contractId, $this->installmentDigests[$contractId]]);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $rows
     * @return list<string>
     */
    private function sorted(array $rows): array
    {
        ksort($rows);

        return array_values($rows);
    }

    /**
     * @param  array<int, list<string>>  $rows
     * @return list<string>
     */
    private function flattened(array $rows): array
    {
        ksort($rows);

        return array_merge(...array_values($rows)) ?: [];
    }
}
