<?php

namespace App\Filament\Resources\Operations\Schemas;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Support\BusinessTime;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Avanço físico inicial do plano de medição, nos dois lugares em que um plano
 * nasce: o modal "Novo Plano" e o formulário da operação (um plano por
 * empreendimento).
 *
 * O valor só é informado na criação. Desabilitado, o campo não é gravado nem
 * validado -- inclusive nos planos antigos com percentual e sem data --, e o
 * model recusa qualquer alteração que chegue por outro caminho.
 */
final class InitialPhysicalProgressFields
{
    public static function percent(): TextInput
    {
        return TextInput::make('initial_physical_progress_percent')
            ->label('Avanço físico inicial (%)')
            ->helperText('Obra já executada antes do acompanhamento no sistema. Não pode ser alterado depois da criação.')
            ->numeric()
            ->step('0.01')
            ->suffix('%')
            ->default(0)
            ->minValue(0)
            ->maxValue(100)
            ->rule('decimal:0,2')
            ->validationMessages([
                'numeric' => 'Informe o avanço físico inicial em percentual.',
                'decimal' => 'Informe o avanço físico inicial com no máximo duas casas decimais.',
                'min' => 'O avanço físico inicial não pode ser negativo.',
                'max' => 'O avanço físico inicial não pode passar de 100%.',
            ])
            ->live(onBlur: true)
            ->validatedWhenNotDehydrated(false)
            ->extraInputAttributes(['class' => 'tabular-nums']);
    }

    public static function referenceDate(): DatePicker
    {
        return DatePicker::make('initial_physical_progress_reference_date')
            ->label('Data de referência do avanço inicial')
            ->helperText('Competências que terminam até esta data já estão no avanço inicial: a Engenharia não aceita avanço nelas.')
            ->displayFormat('d/m/Y')
            ->placeholder('dd/mm/aaaa')
            ->maxDate(fn (): string => BusinessTime::dateString())
            ->required(fn (Get $get): bool => self::isPositive($get('initial_physical_progress_percent')))
            ->validationMessages([
                'required' => 'Informe a data de referência do avanço físico inicial.',
                'before_or_equal' => 'A data de referência do avanço físico inicial não pode ser futura.',
            ])
            ->validatedWhenNotDehydrated(false);
    }

    public static function isPositive(mixed $percent): bool
    {
        $basisPoints = MeasurementPhysicalProgress::basisPoints($percent);

        return $basisPoints !== null && $basisPoints > 0;
    }
}
