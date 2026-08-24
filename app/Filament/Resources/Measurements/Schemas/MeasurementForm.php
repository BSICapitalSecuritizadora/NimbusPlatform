<?php

namespace App\Filament\Resources\Measurements\Schemas;

use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Services\DocumentStorageService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class MeasurementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 12])
            ->components([
                Section::make('Dados da Medição')
                    ->description('Identifique a operação e confirme a competência do envio.')
                    ->columnSpan(['default' => 12, 'lg' => 5])
                    ->schema([
                        Select::make('operation_id')
                            ->label('Operação')
                            ->placeholder('Selecione a operação...')
                            ->relationship(
                                'operation',
                                'title',
                                modifyQueryUsing: fn (Builder $query): Builder => auth()->user() === null
                                    ? $query->whereRaw('1 = 0')
                                    : $query->visibleTo(auth()->user()),
                            )
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
                                'required' => 'Selecione uma operação.',
                            ]),

                        DatePicker::make('reference_month')
                            ->label('Competência')
                            ->placeholder('mm/aaaa')
                            ->displayFormat('m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->helperText('Preenchida automaticamente pela medição selecionada no cronograma.')
                            ->validationMessages([
                                'required' => 'Informe a competência de referência.',
                            ]),

                        Textarea::make('notes')
                            ->label('Observações (Opcional)')
                            ->placeholder('Registre observações contextuais ou detalhes relevantes sobre este envio de medição...')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Arquivo por Empreendimento')
                    ->description('Associe a medição prevista e envie o arquivo correspondente para cada empreendimento.')
                    ->columnSpan(['default' => 12, 'lg' => 7])
                    ->schema([
                        Placeholder::make('empty_operation_notice')
                            ->hiddenLabel()
                            ->content(new HtmlString('<div class="flex items-center gap-3 p-4 text-xs leading-relaxed text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/40 rounded-xl border border-slate-200/80 dark:border-slate-800"><svg class="w-5 h-5 text-slate-400 dark:text-slate-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg><span>Selecione uma operação no painel à esquerda para listar os empreendimentos vinculados e enviar os respectivos arquivos de medição.</span></div>'))
                            ->visible(fn (Get $get): bool => blank($get('operation_id'))),

                        Repeater::make('assets')
                            ->relationship()
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(true)
                            ->deleteAction(fn (Action $action) => $action->tooltip('Remover empreendimento deste envio'))
                            ->reorderable(false)
                            ->minItems(1)
                            ->columns(1)
                            ->itemLabel(fn (array $state): ?string => filled($state['plan_set_id'] ?? null)
                                ? static::planSetLabel($state['plan_set_id'])
                                : 'Empreendimento')
                            ->visible(fn (Get $get): bool => filled($get('operation_id')))
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
                                    ->placeholder('Selecione a medição prevista...')
                                    ->options(fn (Get $get): array => static::scheduleOptionsForPlanSet($get('plan_set_id')))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->required()
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        $date = filled($state)
                                            ? MeasurementPlanLine::whereKey($state)->value('measurement_date')
                                            : null;

                                        if (filled($date)) {
                                            $set('../../reference_month', Carbon::parse($date)->toDateString());
                                        }
                                    })
                                    ->helperText('Selecione a medição prevista à qual este arquivo corresponde.')
                                    ->validationMessages([
                                        'required' => 'Selecione a medição do cronograma correspondente.',
                                    ]),

                                FileUpload::make('storage_path')
                                    ->label('Arquivo da Medição')
                                    ->disk(DocumentStorageService::privateDisk())
                                    ->directory(DocumentStorageService::PRIVATE_PREFIX.'/measurements/assets')
                                    ->acceptedFileTypes((array) config('uploads.measurement.allowed_mimes', ['application/pdf']))
                                    ->maxSize(51200)
                                    ->required()
                                    ->helperText('Formato PDF ou documento aprovado (máx. 50 MB).')
                                    ->validationMessages([
                                        'required' => 'Envie o arquivo da medição deste empreendimento.',
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
            ->mapWithKeys(function (MeasurementPlanLine $line): array {
                $num = str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT);
                $month = $line->measurement_date?->format('m/Y') ?? '—';
                $planned = filled($line->planned_cumulative_percent)
                    ? ' · '.number_format((float) $line->planned_cumulative_percent, 1, ',', '.').'% planejado'
                    : '';

                return [$line->id => "Medição {$num} · {$month}{$planned}"];
            })
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
