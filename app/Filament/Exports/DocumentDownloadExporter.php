<?php

namespace App\Filament\Exports;

use App\Models\DocumentDownload;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class DocumentDownloadExporter extends Exporter
{
    protected static ?string $model = DocumentDownload::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('document.title')->label('Documento'),
            ExportColumn::make('source')->label('Origem'),
            ExportColumn::make('investor.name')->label('Investidor'),
            ExportColumn::make('adminUser.name')->label('Usuário Admin'),
            ExportColumn::make('downloaded_at')->label('Data e Hora'),
            ExportColumn::make('ip')->label('Endereço IP'),
            ExportColumn::make('user_agent')->label('Navegador / Dispositivo'),
            ExportColumn::make('referer')->label('Origem do Acesso'),
            ExportColumn::make('session_id')->label('ID da Sessão'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'A exportação do histórico de downloads foi concluída com '.Number::format($export->successful_rows).' '.str('registro')->plural($export->successful_rows).' exportado(s).';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('registro')->plural($failedRowsCount).' falhou na exportação.';
        }

        return $body;
    }
}
