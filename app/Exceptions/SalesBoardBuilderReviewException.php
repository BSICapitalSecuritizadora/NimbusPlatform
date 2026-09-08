<?php

namespace App\Exceptions;

use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardStaleImpact;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas do fluxo de validação da construtora.
 *
 * Todas são situações previsíveis do domínio -- a versão mudou, a seção não foi
 * respondida, a revisão já foi enviada -- e não defeitos. Por isso não sobem
 * para o log de erros: viram mensagem para quem está na tela.
 */
class SalesBoardBuilderReviewException extends RuntimeException implements ShouldntReport
{
    public static function cycleNotReviewable(SalesBoardCycleStatus $status): self
    {
        return new self(sprintf(
            'Não é possível abrir uma validação: a competência está em "%s".',
            $status->label(),
        ));
    }

    public static function withoutCurrentBaseline(): self
    {
        return new self('A competência não tem versão vigente para ser validada.');
    }

    /**
     * A construtora valida um quadro fechado. Mandar para ela uma posição que já
     * se sabe desatualizada produziria uma validação sobre números que ninguém
     * pretende manter.
     */
    public static function baselineNotEligible(SalesBoardStaleImpact $impact): self
    {
        return new self(match ($impact) {
            SalesBoardStaleImpact::Blocking => 'A fonte da competência está incompleta. '
                .'Resolva os dados pendentes antes de enviá-la à construtora.',
            default => 'A posição possui alteração material pendente. '
                .'Recalcule antes de enviá-la à construtora.',
        });
    }

    public static function reviewNotEditable(): self
    {
        return new self('Esta revisão já foi enviada e não pode mais ser alterada.');
    }

    public static function alreadySubmitted(): self
    {
        return new self('Esta revisão já foi enviada para análise.');
    }

    public static function baselineChanged(): self
    {
        return new self('Uma nova versão da posição foi gerada. '
            .'Atualize a revisão antes de enviá-la.');
    }

    /**
     * @param  list<SalesBoardBuilderReviewSection>  $sections
     */
    public static function sectionsPending(array $sections): self
    {
        return new self(sprintf(
            'Ainda falta validar: %s.',
            implode(', ', array_map(
                fn (SalesBoardBuilderReviewSection $section): string => $section->label(),
                $sections,
            )),
        ));
    }

    public static function divergentSectionWithoutDivergence(SalesBoardBuilderReviewSection $section): self
    {
        return new self(sprintf(
            'A seção "%s" está marcada como divergente mas não tem nenhuma divergência registrada.',
            $section->label(),
        ));
    }

    public static function cannotConfirmWithDivergences(SalesBoardBuilderReviewSection $section): self
    {
        return new self(sprintf(
            'A seção "%s" tem divergências registradas e não pode ser confirmada. '
                .'Remova as divergências ou envie a seção como divergente.',
            $section->label(),
        ));
    }

    public static function reasonRequired(): self
    {
        return new self('Descreva o motivo da divergência.');
    }

    public static function sectionNotAllowedForType(
        SalesBoardBuilderDivergenceType $type,
        SalesBoardBuilderReviewSection $section,
    ): self {
        return new self(sprintf(
            'A divergência "%s" não pertence à seção "%s".',
            $type->label(),
            $section->label(),
        ));
    }

    public static function anchorRequired(SalesBoardBuilderDivergenceType $type): self
    {
        return new self(sprintf(
            'A divergência "%s" precisa apontar a linha ou a movimentação a que se refere.',
            $type->label(),
        ));
    }

    public static function movementAnchorRequired(SalesBoardBuilderDivergenceType $type): self
    {
        return new self(sprintf(
            'A divergência "%s" precisa apontar a movimentação a que se refere.',
            $type->label(),
        ));
    }

    public static function lineAnchorRequired(SalesBoardBuilderDivergenceType $type): self
    {
        return new self(sprintf(
            'A divergência "%s" precisa apontar a unidade a que se refere.',
            $type->label(),
        ));
    }

    public static function anchorOutsideReview(): self
    {
        return new self('A linha ou movimentação apontada não pertence à versão que está sendo validada.');
    }

    public static function anchorSectionMismatch(SalesBoardBuilderReviewSection $section): self
    {
        return new self(sprintf(
            'A linha ou movimentação apontada não pertence à seção "%s".',
            $section->label(),
        ));
    }

    /**
     * @param  list<string>  $fields
     */
    public static function declarationRequired(SalesBoardBuilderDivergenceType $type, array $fields): self
    {
        $labels = [
            'declared_block' => 'bloco',
            'declared_unit' => 'unidade',
            'declared_contract_code' => 'contrato',
            'declared_value' => 'valor',
            'declared_date' => 'data',
            'declared_classification' => 'classificação',
        ];

        return new self(sprintf(
            'A divergência "%s" exige informar: %s.',
            $type->label(),
            implode(', ', array_map(fn (string $field): string => $labels[$field] ?? $field, $fields)),
        ));
    }

    public static function reviewerIdentityRequired(): self
    {
        return new self('Não foi possível identificar quem está enviando esta validação.');
    }
}
