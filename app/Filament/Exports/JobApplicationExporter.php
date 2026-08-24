<?php

namespace App\Filament\Exports;

use App\Models\JobApplication;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class JobApplicationExporter extends Exporter
{
    protected static ?string $model = JobApplication::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('vacancy.title')->label('Vaga'),
            ExportColumn::make('name')->label('Candidato'),
            ExportColumn::make('email')->label('E-mail'),
            ExportColumn::make('phone')->label('Telefone'),
            ExportColumn::make('linkedin_url')->label('LinkedIn'),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn (?string $state): string => JobApplication::statusLabelFor($state)),
            ExportColumn::make('reviewedBy.name')->label('Avaliada por'),
            ExportColumn::make('reviewed_at')
                ->label('Avaliada em')
                ->formatStateUsing(fn (?string $state): string => $state ? Carbon::parse($state)->format('d/m/Y H:i') : '—'),
            ExportColumn::make('created_at')
                ->label('Recebida em')
                ->formatStateUsing(fn (?string $state): string => $state ? Carbon::parse($state)->format('d/m/Y H:i') : '—'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'A exportação de candidaturas foi concluída com '.Number::format($export->successful_rows).' '.str('registro')->plural($export->successful_rows).' exportado(s).';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('registro')->plural($failedRowsCount).' falhou na exportação.';
        }

        $body .= ' Atenção LGPD: o arquivo contém dados pessoais e deve ser tratado conforme a Política de Privacidade.';

        return $body;
    }
}
