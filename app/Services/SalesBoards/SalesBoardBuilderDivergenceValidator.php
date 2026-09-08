<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;

/**
 * Garante que uma declaração da construtora esteja completa e coerente.
 *
 * Uma divergência serve para a Gestão investigar. "O valor está errado", sem
 * dizer qual seria o valor, não é investigável -- e uma declaração incompleta
 * descoberta semanas depois, na análise, custa uma ida e volta inteira com a
 * construtora. Por isso a completude é exigida na hora de registrar.
 *
 * As regras vivem no {@see App\Enums\SalesBoardBuilderDivergenceType}; aqui elas
 * são aplicadas. Além delas, três coerências que nenhum enum consegue expressar:
 * a âncora precisa pertencer à versão que está sendo validada, precisa pertencer
 * à seção em que a divergência foi aberta, e o motivo nunca pode estar vazio.
 */
class SalesBoardBuilderDivergenceValidator
{
    private const REASON_MAX_LENGTH = 2000;

    public function validate(
        SalesBoardBuilderReview $review,
        SalesBoardBuilderReviewSection $section,
        SalesBoardBuilderDivergenceInput $input,
    ): void {
        $type = $input->type;

        if (trim($input->reason) === '') {
            throw SalesBoardBuilderReviewException::reasonRequired();
        }

        if (mb_strlen($input->reason) > self::REASON_MAX_LENGTH) {
            throw SalesBoardBuilderReviewException::reasonRequired();
        }

        if (! in_array($section->section, $type->sections(), true)) {
            throw SalesBoardBuilderReviewException::sectionNotAllowedForType($type, $section->section);
        }

        $line = $this->resolveLine($review, $input->lineId);
        $movement = $this->resolveMovement($review, $input->movementId);

        $this->assertAnchorPresence($input, $line, $movement);
        $this->assertAnchorMatchesSection($section->section, $line, $movement);
        $this->assertDeclarations($input);
    }

    private function resolveLine(SalesBoardBuilderReview $review, ?int $lineId): ?SalesBoardCycleLine
    {
        if ($lineId === null) {
            return null;
        }

        $line = SalesBoardCycleLine::query()->find($lineId);

        /**
         * A âncora tem de pertencer à versão revisada. Sem esta checagem, uma
         * divergência poderia apontar para a linha de outra competência ou de
         * outra versão, e a Gestão analisaria a discordância contra um fato que
         * a construtora nunca viu.
         */
        if (($line === null)
            || ((int) $line->sales_board_cycle_baseline_id !== (int) $review->sales_board_cycle_baseline_id)) {
            throw SalesBoardBuilderReviewException::anchorOutsideReview();
        }

        return $line;
    }

    private function resolveMovement(SalesBoardBuilderReview $review, ?int $movementId): ?SalesBoardCycleMovement
    {
        if ($movementId === null) {
            return null;
        }

        $movement = SalesBoardCycleMovement::query()->find($movementId);

        if (($movement === null)
            || ((int) $movement->sales_board_cycle_baseline_id !== (int) $review->sales_board_cycle_baseline_id)) {
            throw SalesBoardBuilderReviewException::anchorOutsideReview();
        }

        return $movement;
    }

    private function assertAnchorPresence(
        SalesBoardBuilderDivergenceInput $input,
        ?SalesBoardCycleLine $line,
        ?SalesBoardCycleMovement $movement,
    ): void {
        $type = $input->type;
        $requiredMovementType = $type->requiredMovementType();

        if ($requiredMovementType !== null) {
            if ($movement === null) {
                throw SalesBoardBuilderReviewException::movementAnchorRequired($type);
            }

            if ($movement->movement_type !== $requiredMovementType) {
                throw SalesBoardBuilderReviewException::anchorSectionMismatch(
                    $type->sections()[0],
                );
            }

            return;
        }

        if ($type->requiresLine() && ($line === null)) {
            throw SalesBoardBuilderReviewException::lineAnchorRequired($type);
        }

        if ($type->requiresAnyAnchor() && ($line === null) && ($movement === null)) {
            throw SalesBoardBuilderReviewException::anchorRequired($type);
        }
    }

    /**
     * A âncora precisa pertencer à seção em que a divergência foi aberta.
     *
     * É o que impede uma discordância sobre uma unidade financiada de ser
     * arquivada dentro da seção de estoque -- que a construtora confirmou --
     * deixando a seção confirmada e a divergência escondida em outro lugar.
     */
    private function assertAnchorMatchesSection(
        SectionEnum $section,
        ?SalesBoardCycleLine $line,
        ?SalesBoardCycleMovement $movement,
    ): void {
        if (($line !== null) && $section->isPosition() && ($line->classification !== $section->classification())) {
            throw SalesBoardBuilderReviewException::anchorSectionMismatch($section);
        }

        if (($movement !== null) && ! $section->isPosition() && ($movement->movement_type !== $section->movementType())) {
            throw SalesBoardBuilderReviewException::anchorSectionMismatch($section);
        }
    }

    private function assertDeclarations(SalesBoardBuilderDivergenceInput $input): void
    {
        $provided = $input->providedDeclarations();

        $missing = array_values(array_diff($input->type->requiredDeclarations(), $provided));

        if ($missing !== []) {
            throw SalesBoardBuilderReviewException::declarationRequired($input->type, $missing);
        }

        $anyOf = $input->type->requiredAnyDeclarations();

        if (($anyOf !== []) && (array_intersect($anyOf, $provided) === [])) {
            throw SalesBoardBuilderReviewException::declarationRequired($input->type, $anyOf);
        }
    }
}
