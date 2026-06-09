<?php

namespace App\Filament\Resources\Measurements\Schemas;

use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class MeasurementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Medição')
                ->columns(2)
                ->schema([
                    Select::make('operation_id')
                        ->label('Operação')
                        ->relationship('operation', 'title')
                        ->getOptionLabelFromRecordUsing(fn (Operation $record): string => trim(($record->code ? $record->code.' — ' : '').$record->title))
                        ->searchable(['title', 'code'])
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state !== $old) {
                                $set('assets', static::assetsForOperation($state));
                            }
                        })
                        ->validationMessages([
                            'required' => 'Selecione a operação.',
                        ]),

                    DatePicker::make('reference_month')
                        ->label('Competência')
                        ->displayFormat('m/Y')
                        ->native(false)
                        ->closeOnDateSelection()
                        ->helperText('Preenchida pela medição selecionada em cada empreendimento; ajuste se necessário.'),

                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Arquivo por Empreendimento')
                ->description('Os empreendimentos da operação já vêm listados. Envie o arquivo de cada um.')
                ->schema([
                    Repeater::make('assets')
                        ->relationship()
                        ->label('Empreendimentos')
                        ->addable(false)
                        ->deletable(true)
                        ->reorderable(false)
                        ->minItems(1)
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string => filled($state['plan_set_id'] ?? null)
                            ? static::planSetLabel($state['plan_set_id'])
                            : null)
                        ->schema([
                            Select::make('plan_set_id')
                                ->label('Empreendimento')
                                ->options(fn (Get $get): array => static::planSetOptions($get('../../operation_id')))
                                ->required()
                                ->disabled()
                                ->dehydrated()
                                ->validationMessages([
                                    'required' => 'Empreendimento inválido.',
                                ]),

                            Select::make('plan_line_id')
                                ->label('Medição do cronograma')
                                ->options(fn (Get $get): array => static::scheduleOptionsForPlanSet($get('plan_set_id')))
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    $date = filled($state)
                                        ? MeasurementPlanLine::whereKey($state)->value('measurement_date')
                                        : null;

                                    if (filled($date)) {
                                        $set('../../reference_month', \Illuminate\Support\Carbon::parse($date)->toDateString());
                                    }
                                })
                                ->helperText('A qual medição prevista deste empreendimento o arquivo corresponde.'),

                            FileUpload::make('storage_path')
                                ->label('Arquivo da Medição')
                                ->disk('public')
                                ->directory('measurements')
                                ->downloadable()
                                ->openable()
                                ->required()
                                ->validationMessages([
                                    'required' => 'Envie o arquivo deste empreendimento.',
                                ]),
                        ]),
                ]),
        ]);
    }

    /**
     * Builds one asset row per development of the operation, with the plan set
     * pre-filled and the file/measurement left blank to fill.
     *
     * @return array<int, array{plan_set_id: int, plan_line_id: null, storage_path: null}>
     */
    protected static function assetsForOperation(mixed $operationId): array
    {
        if (blank($operationId)) {
            return [];
        }

        return MeasurementPlanSet::query()
            ->where('operation_id', $operationId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int $id): array => [
                'plan_set_id' => $id,
                'plan_line_id' => null,
                'storage_path' => null,
            ])
            ->all();
    }

    /**
     * Lists the scheduled measurements of a single development (plan set) keyed by
     * line id, so each uploaded file can be tied to that development's measurement.
     *
     * @return array<int, string>
     */
    protected static function scheduleOptionsForPlanSet(mixed $planSetId): array
    {
        if (blank($planSetId)) {
            return [];
        }

        return MeasurementPlanLine::query()
            ->where('plan_set_id', $planSetId)
            ->whereNotNull('measurement_date')
            ->orderBy('sequence_number')
            ->get()
            ->mapWithKeys(fn (MeasurementPlanLine $line): array => [
                $line->id => 'Medição #'.$line->sequence_number.' — '.$line->measurement_date->format('m/Y'),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function planSetOptions(mixed $operationId): array
    {
        if (blank($operationId)) {
            return [];
        }

        return MeasurementPlanSet::query()
            ->where('operation_id', $operationId)
            ->with('construction')
            ->get()
            ->mapWithKeys(fn (MeasurementPlanSet $planSet): array => [
                $planSet->id => $planSet->construction?->development_name ?? $planSet->name,
            ])
            ->all();
    }

    protected static function planSetLabel(mixed $planSetId): ?string
    {
        $planSet = MeasurementPlanSet::query()->with('construction')->find($planSetId);

        return $planSet?->construction?->development_name ?? $planSet?->name;
    }
}
