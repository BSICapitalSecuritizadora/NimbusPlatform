<?php

namespace App\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;
use Spatie\Activitylog\Models\Activity;

class ActivityExporter extends Exporter
{
    protected static ?string $model = Activity::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('log_name')->label('Log'),
            ExportColumn::make('event')->label('Tipo de Evento'),
            ExportColumn::make('description')->label('Ação Executada'),
            ExportColumn::make('subject_type')->label('Entidade Modificada'),
            ExportColumn::make('subject_id')->label('ID da Entidade'),
            ExportColumn::make('causer.name')->label('Usuário Autor'),
            ExportColumn::make('created_at')->label('Data e Hora'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'A exportação de logs de auditoria foi concluída com '.Number::format($export->successful_rows).' '.str('registro')->plural($export->successful_rows).' exportado(s).';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('registro')->plural($failedRowsCount).' falhou na exportação.';
        }

        return $body;
    }
}
