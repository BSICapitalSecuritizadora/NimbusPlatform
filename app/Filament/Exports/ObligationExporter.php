<?php

namespace App\Filament\Exports;

use App\Enums\AccessPermission;
use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class ObligationExporter extends Exporter
{
    protected static ?string $model = Obligation::class;

    public static function getColumns(): array
    {
        $dashboardData = app(ObligationDashboardData::class);
        $canViewEvidence = (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);

        $columns = [
            ExportColumn::make('emission.name')
                ->label('Emissão'),
            ExportColumn::make('emission.bsi_code')
                ->label('Código da emissão'),
            ExportColumn::make('title')
                ->label('Título da obrigação'),
            ExportColumn::make('description')
                ->label('Descrição / resumo')
                ->state(fn (Obligation $record): ?string => filled($record->description) ? Str::squish((string) $record->description) : null),
            ExportColumn::make('status')
                ->label('Status da obrigação')
                ->formatStateUsing(fn (?string $state): string => Obligation::STATUS_OPTIONS[$state] ?? (string) $state),
            ExportColumn::make('due_date')
                ->label('Data de vencimento')
                ->state(fn (Obligation $record): ?string => self::formatDateValue($record->due_date)),
            ExportColumn::make('aging')
                ->label('Aging')
                ->state(fn (Obligation $record): ?string => $dashboardData->agingLabelFor($record)),
            ExportColumn::make('responsibleUser.name')
                ->label('Responsável'),
            ExportColumn::make('responsible_area')
                ->label('Área responsável'),
            ExportColumn::make('priority')
                ->label('Prioridade')
                ->formatStateUsing(fn (?string $state): string => Obligation::PRIORITY_OPTIONS[$state] ?? (string) $state),
            ExportColumn::make('source')
                ->label('Origem')
                ->state(fn (Obligation $record): string => $record->extracted_obligation_id !== null ? 'Gerada pelo Termo' : 'Manual'),
            ExportColumn::make('created_at')
                ->label('Criada em')
                ->state(fn (Obligation $record): ?string => self::formatDateTimeValue($record->created_at)),
            ExportColumn::make('updated_at')
                ->label('Atualizada em')
                ->state(fn (Obligation $record): ?string => self::formatDateTimeValue($record->updated_at)),
            ExportColumn::make('completed_at')
                ->label('Concluída em')
                ->state(fn (Obligation $record): ?string => self::formatDateTimeValue($record->completed_at)),
            ExportColumn::make('completedByUser.name')
                ->label('Concluída por'),
        ];

        if (! $canViewEvidence) {
            return $columns;
        }

        return [
            ...$columns,
            ExportColumn::make('document_status')
                ->label('Status documental')
                ->state(fn (Obligation $record): string => $dashboardData->documentStatusFor($record)),
            ExportColumn::make('evidences_count')
                ->label('Quantidade de evidências')
                ->state(fn (Obligation $record): int => (int) ($record->evidences_count ?? 0)),
            ExportColumn::make('approved_evidences_count')
                ->label('Evidências aprovadas')
                ->state(fn (Obligation $record): int => (int) ($record->approved_evidences_count ?? 0)),
            ExportColumn::make('pending_evidences_count')
                ->label('Evidências pendentes')
                ->state(fn (Obligation $record): int => (int) ($record->pending_evidences_count ?? 0)),
            ExportColumn::make('rejected_evidences_count')
                ->label('Evidências rejeitadas')
                ->state(fn (Obligation $record): int => (int) ($record->rejected_evidences_count ?? 0)),
            ExportColumn::make('evidences_max_uploaded_at')
                ->label('Última evidência enviada em')
                ->max('evidences', 'uploaded_at')
                ->formatStateUsing(fn (mixed $state): ?string => self::formatDateTimeValue($state)),
            ExportColumn::make('evidences_max_reviewed_at')
                ->label('Última evidência revisada em')
                ->max([
                    'evidences' => fn ($query) => $query->whereNotNull('reviewed_at'),
                ], 'reviewed_at')
                ->formatStateUsing(fn (mixed $state): ?string => self::formatDateTimeValue($state)),
        ];
    }

    public function getFormats(): array
    {
        return [
            ExportFormat::Xlsx,
            ExportFormat::Csv,
        ];
    }

    public function getFileName(Export $export): string
    {
        return 'obrigacoes-operacionais-'.$export->getKey();
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'A exportação de obrigações foi concluída com '.Number::format($export->successful_rows).' '.str('registro')->plural($export->successful_rows).' exportado(s).';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('registro')->plural($failedRowsCount).' falhou na exportação.';
        }

        return $body;
    }

    protected static function formatDateValue(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        return Carbon::parse($value)->format('d/m/Y');
    }

    protected static function formatDateTimeValue(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y H:i');
        }

        return Carbon::parse($value)->format('d/m/Y H:i');
    }
}
