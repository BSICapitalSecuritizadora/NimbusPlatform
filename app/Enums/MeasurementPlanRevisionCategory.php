<?php

namespace App\Enums;

/**
 * Por que uma revisão do plano de medição existe. A justificativa em texto
 * livre continua obrigatória para ativar; a categoria só classifica.
 */
enum MeasurementPlanRevisionCategory: string
{
    case Schedule = 'schedule';
    case Cost = 'cost';
    case Scope = 'scope';
    case PhysicalPlanning = 'physical_planning';
    case Multiple = 'multiple';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Schedule => 'Cronograma',
            self::Cost => 'Custo',
            self::Scope => 'Escopo',
            self::PhysicalPlanning => 'Planejamento físico',
            self::Multiple => 'Múltiplos fatores',
            self::Other => 'Outro',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $category) {
            $options[$category->value] = $category->label();
        }

        return $options;
    }
}
