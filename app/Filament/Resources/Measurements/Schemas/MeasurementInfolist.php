<?php

namespace App\Filament\Resources\Measurements\Schemas;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementReview;
use App\Services\MeasurementWorkflow;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class MeasurementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Medição')
                ->columns(3)
                ->schema([
                    TextEntry::make('operation.title')->label('Operação'),
                    TextEntry::make('reference_month')->label('Competência')->date('m/Y')->placeholder('—'),
                    TextEntry::make('status')
                        ->label('Situação')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => Measurement::STATUS_OPTIONS[$state] ?? $state),
                    TextEntry::make('current_stage')
                        ->label('Etapa atual')
                        ->badge()
                        ->state(fn (Measurement $record): string => MeasurementWorkflow::STAGE_LABELS[app(MeasurementWorkflow::class)->unifiedStage($record)] ?? '—')
                        ->color(fn (Measurement $record): string => MeasurementWorkflow::STAGE_COLORS[app(MeasurementWorkflow::class)->unifiedStage($record)] ?? 'gray'),
                    TextEntry::make('uploadedByUser.name')->label('Enviada por')->placeholder('—'),
                    TextEntry::make('uploaded_at')->label('Enviada em')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('notes')->label('Observações')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Arquivos por Empreendimento')
                ->schema([
                    RepeatableEntry::make('assets')
                        ->label('')
                        ->columns(2)
                        ->schema([
                            TextEntry::make('planSet.construction.development_name')->label('Empreendimento')->placeholder('—'),
                            TextEntry::make('storage_path')
                                ->label('Arquivo')
                                ->state(fn (MeasurementAsset $record): ?string => filled($record->storage_path) ? 'Abrir arquivo' : null)
                                ->url(fn (MeasurementAsset $record): ?string => filled($record->storage_path) ? Storage::disk('public')->url($record->storage_path) : null)
                                ->openUrlInNewTab()
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('primary')
                                ->placeholder('—'),
                        ]),
                ]),

            Section::make('Linha do Tempo')
                ->description('Histórico completo da medição: envio, aprovações, recusas, devoluções, pausas e pagamentos.')
                ->collapsible()
                ->schema([
                    ViewEntry::make('timeline')
                        ->label('')
                        ->view('filament.infolists.measurement-timeline'),
                ]),

            Section::make('Análises por Etapa')
                ->schema([
                    RepeatableEntry::make('reviews')
                        ->label('')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('stage')
                                ->label('Etapa')
                                ->badge()
                                ->formatStateUsing(fn (int $state): string => MeasurementWorkflow::STAGE_LABELS[$state] ?? (string) $state)
                                ->color(fn (int $state): string => MeasurementWorkflow::STAGE_COLORS[$state] ?? 'gray'),
                            TextEntry::make('reviewer.name')->label('Responsável')->placeholder('—'),
                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn (string $state): string => MeasurementReview::STATUS_OPTIONS[$state] ?? $state)
                                ->color(fn (string $state): string => match ($state) {
                                    'approved' => 'success',
                                    'rejected' => 'danger',
                                    default => 'gray',
                                }),
                            TextEntry::make('reviewed_at')->label('Analisada em')->dateTime('d/m/Y H:i')->placeholder('—'),
                            TextEntry::make('notes')->label('Comentário')->placeholder('—')->columnSpanFull(),
                        ]),
                ]),
        ]);
    }
}
