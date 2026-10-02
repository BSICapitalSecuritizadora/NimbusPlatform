<?php

declare(strict_types=1);

namespace App\DTOs\Guarantees;

use App\DTOs\BaseDTO;
use App\DTOs\SalesBoards\ConstructionSalesPosition;
use App\DTOs\SalesBoards\EmissionSalesPosition;
use App\Enums\SalesBoardPositionStatus;
use Carbon\CarbonImmutable;

/**
 * De qual Quadro de Vendas saiu o valor das garantias de estoque numa
 * competência: a competência do quadro usada por empreendimento e se ela era a
 * do próprio mês, transportada da última posição conhecida ou ausente.
 *
 * O leitor de posição sempre calculou isso; as garantias descartavam. Sem este
 * registro, um estoque transportado de dois meses antes ou um empreendimento
 * sem quadro somavam como se a posição fosse completa — e, fechada a
 * competência, o número ficava imutável sem ninguém saber que era parcial.
 *
 * `emissionWide` distingue a garantia que recai sobre a emissão inteira (todo
 * empreendimento que passar a ter quadro entra na soma) da que recai sobre
 * empreendimentos específicos.
 */
readonly class GuaranteeSalesBoardCoverage extends BaseDTO
{
    /**
     * @param  list<array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}>  $constructions
     */
    public function __construct(
        public bool $emissionWide,
        public array $constructions,
    ) {}

    /**
     * @param  list<ConstructionSalesPosition>  $positions
     */
    public static function fromConstructionPositions(bool $emissionWide, array $positions): self
    {
        return new self(
            emissionWide: $emissionWide,
            constructions: array_values(array_map(
                fn (ConstructionSalesPosition $position): array => [
                    'construction_id' => $position->constructionId,
                    'construction_name' => $position->constructionName,
                    'status' => $position->status->value,
                    'reference_month_used' => $position->referenceMonthUsedDate(),
                ],
                $positions,
            )),
        );
    }

    /**
     * Reidrata o que foi gravado no snapshot ou na memória de cálculo.
     */
    public static function fromArray(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_array($value) || ! array_key_exists('constructions', $value)) {
            return null;
        }

        $constructions = [];

        foreach ((array) $value['constructions'] as $entry) {
            if (! is_array($entry) || ! isset($entry['construction_id'])) {
                continue;
            }

            $constructions[] = [
                'construction_id' => (int) $entry['construction_id'],
                'construction_name' => self::nullableString($entry['construction_name'] ?? null),
                'status' => (string) ($entry['status'] ?? SalesBoardPositionStatus::Unpositioned->value),
                'reference_month_used' => self::nullableString($entry['reference_month_used'] ?? null),
            ];
        }

        return new self((bool) ($value['emission_wide'] ?? false), $constructions);
    }

    /**
     * União das coberturas de várias garantias: um empreendimento aparece uma
     * vez só, e basta uma garantia sobre a emissão inteira para o conjunto ser
     * da emissão inteira.
     *
     * @param  iterable<self|null>  $coverages
     */
    public static function merge(iterable $coverages): ?self
    {
        $found = false;
        $emissionWide = false;
        $constructions = [];

        foreach ($coverages as $coverage) {
            if (! $coverage instanceof self) {
                continue;
            }

            $found = true;
            $emissionWide = $emissionWide || $coverage->emissionWide;

            foreach ($coverage->constructions as $entry) {
                $constructions[$entry['construction_id']] = $entry;
            }
        }

        if (! $found) {
            return null;
        }

        ksort($constructions);

        return new self($emissionWide, array_values($constructions));
    }

    /**
     * @return array{emission_wide: bool, constructions: list<array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'emission_wide' => $this->emissionWide,
            'constructions' => $this->constructions,
        ];
    }

    /**
     * Empreendimentos sem o quadro da própria competência: posição transportada,
     * sem quadro até a competência ou sem quadro algum.
     *
     * Aqui as garantias divergem do leitor de posição de propósito. Para
     * {@see EmissionSalesPosition::isFullyCovered()}, o empreendimento cujo
     * primeiro quadro é posterior à competência não é esperado -- cobrar dele
     * uma posição que ainda não existia inventaria uma falta no painel de
     * unidades. Para as garantias ele é lacuna: na competência a obra já tinha
     * unidades, e elas ficaram fora da soma do estoque. A decisão do dono
     * (25/09) pede a confirmação explícita para fechar com "obra sem quadro do
     * mês", e o erro silencioso seria subestimar a cobertura sem ninguém saber.
     * Ele continua registrado em `constructions`, sem mês usado, e um quadro
     * retroativo dele ainda desatualiza a apuração ({@see self::dependsOn()}).
     *
     * @return list<array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}>
     */
    public function gaps(): array
    {
        return array_values(array_filter(
            $this->constructions,
            fn (array $entry): bool => self::isGap($entry),
        ));
    }

    /**
     * Lacunas cuja posição foi transportada de um quadro anterior.
     *
     * @return list<array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}>
     */
    public function carriedForwardGaps(): array
    {
        return array_values(array_filter(
            $this->gaps(),
            fn (array $entry): bool => $entry['status'] === SalesBoardPositionStatus::CarriedForward->value,
        ));
    }

    /**
     * Lacunas sem posição nenhuma na competência: o empreendimento nunca teve
     * quadro, ou só passou a ter depois dela.
     *
     * @return list<array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}>
     */
    public function unpositionedGaps(): array
    {
        return array_values(array_filter(
            $this->gaps(),
            fn (array $entry): bool => $entry['status'] !== SalesBoardPositionStatus::CarriedForward->value,
        ));
    }

    public function hasGaps(): bool
    {
        return $this->gaps() !== [];
    }

    /**
     * Identidade das lacunas, para comparar o que o usuário confirmou com o que
     * o servidor encontra na hora de gravar. Um quadro publicado entre a
     * abertura do modal e o clique muda a identidade, e a confirmação deixa de
     * valer.
     */
    public function gapsFingerprint(): string
    {
        return implode('|', array_map(
            fn (array $entry): string => sprintf(
                '%d:%s:%s',
                $entry['construction_id'],
                $entry['status'],
                $entry['reference_month_used'] ?? '-',
            ),
            $this->gaps(),
        ));
    }

    /**
     * @return list<string>
     */
    public function gapDescriptions(): array
    {
        return array_map(
            fn (array $entry): string => self::describeEntry($entry),
            $this->gaps(),
        );
    }

    /**
     * Um quadro do empreendimento na competência `$referenceMonth` (criado,
     * alterado ou excluído) muda o número desta apuração?
     *
     * Muda quando o empreendimento entrou na apuração com um quadro igual ou
     * anterior ao alterado — ou sem quadro —, ou quando ele ainda não fazia
     * parte dela e a garantia recai sobre a emissão inteira. Um quadro mais
     * novo que o alterado continua respondendo pela competência, então aí nada
     * muda.
     */
    public function dependsOn(int $constructionId, string $referenceMonth): bool
    {
        foreach ($this->constructions as $entry) {
            if ($entry['construction_id'] !== $constructionId) {
                continue;
            }

            return $entry['reference_month_used'] === null
                || $entry['reference_month_used'] <= $referenceMonth;
        }

        return $this->emissionWide;
    }

    /**
     * Só o quadro da própria competência não é lacuna. Todo o resto -- posição
     * transportada, sem quadro até a competência, sem quadro algum e status
     * desconhecido -- pede confirmação em vez de passar calado.
     *
     * @param  array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}  $entry
     */
    private static function isGap(array $entry): bool
    {
        return SalesBoardPositionStatus::tryFrom($entry['status']) !== SalesBoardPositionStatus::Current;
    }

    /**
     * @param  array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}  $entry
     */
    public static function describeEntry(array $entry): string
    {
        $name = self::constructionLabel($entry);

        $status = SalesBoardPositionStatus::tryFrom($entry['status']);
        $label = $status?->label() ?? 'Sem quadro de vendas';

        if ($entry['reference_month_used'] === null) {
            return sprintf('%s: %s', $name, mb_strtolower($label));
        }

        return sprintf(
            '%s: %s (%s)',
            $name,
            mb_strtolower($label),
            CarbonImmutable::parse($entry['reference_month_used'])->format('m/Y'),
        );
    }

    /**
     * Só o empreendimento e o mês do quadro usado: "Residencial Alfa (08/2026)".
     *
     * @param  array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}  $entry
     */
    public static function describeMonthUsed(array $entry): string
    {
        if ($entry['reference_month_used'] === null) {
            return self::describeEntry($entry);
        }

        return sprintf(
            '%s (%s)',
            self::constructionLabel($entry),
            CarbonImmutable::parse($entry['reference_month_used'])->format('m/Y'),
        );
    }

    /**
     * @param  array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}  $entry
     */
    private static function constructionLabel(array $entry): string
    {
        $name = $entry['construction_name'] ?? null;

        return filled($name) ? $name : sprintf('Empreendimento #%d', $entry['construction_id']);
    }
}
