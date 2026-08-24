<?php

namespace App\Filament\Resources\Recruitment\Tables;

use App\Enums\MalwareScanStatus;
use App\Filament\Exports\JobApplicationExporter;
use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Jobs\SendJobApplicationStatusMail;
use App\Models\JobApplication;
use App\Services\Recruitment\JobApplicationStatusService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (JobApplication $record): string => JobApplicationResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Candidato')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => JobApplication::statusLabelFor($state))
                    ->color(fn (?string $state): string => JobApplication::statusColorFor($state))
                    ->sortable(),
                TextColumn::make('vacancy.title')
                    ->label('Vaga')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scan_status')
                    ->label('Antivírus')
                    ->badge()
                    ->formatStateUsing(fn (?MalwareScanStatus $state): string => match ($state) {
                        MalwareScanStatus::Clean => 'Limpo',
                        MalwareScanStatus::Infected => 'Infectado',
                        MalwareScanStatus::Pending => 'Pendente',
                        default => $state?->value ?? '—',
                    })
                    ->color(fn (?MalwareScanStatus $state): string => match ($state) {
                        MalwareScanStatus::Clean => 'success',
                        MalwareScanStatus::Infected => 'danger',
                        MalwareScanStatus::Pending => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewedBy.name')
                    ->label('Movimentada por')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reviewed_at')
                    ->label('Última Movimentação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Recebida em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(JobApplication::statusOptions())
                    ->multiple(),
                SelectFilter::make('vacancy_id')
                    ->label('Vaga')
                    ->relationship('vacancy', 'title')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('scan_status')
                    ->label('Antivírus')
                    ->options([
                        MalwareScanStatus::Pending->value => 'Pendente',
                        MalwareScanStatus::Clean->value => 'Limpo',
                        MalwareScanStatus::Infected->value => 'Infectado',
                    ]),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')->label('Recebida de'),
                        DatePicker::make('created_until')->label('Recebida até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Action::make('change_status')
                    ->label('Mover')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('gray')
                    ->form([
                        Select::make('status')
                            ->label('Novo Status')
                            ->options(JobApplication::statusOptions())
                            ->required()
                            ->native(false),
                        Textarea::make('note')
                            ->label('Observação (opcional)')
                            ->rows(3)
                            ->placeholder('Motivo da movimentação'),
                    ])
                    ->authorize(fn (JobApplication $record): bool => Gate::allows('update', $record))
                    ->action(function (JobApplication $record, array $data): void {
                        $changed = JobApplicationStatusService::changeStatus($record, $data['status'], $data['note'] ?? null);

                        if ($changed) {
                            if (in_array($data['status'], [JobApplication::STATUS_HIRED, JobApplication::STATUS_REJECTED], true)) {
                                SendJobApplicationStatusMail::dispatch($record->fresh(['vacancy']));
                            }

                            Notification::make()->title('Candidatura movida para '.JobApplication::statusLabelFor($data['status']).'.')->success()->send();
                        } else {
                            Notification::make()->title('Candidatura já está neste status.')->warning()->send();
                        }
                    }),
                EditAction::make()
                    ->label('Avaliar')
                    ->modalHeading('Avaliar Candidatura'),
                ViewAction::make()
                    ->label('Visualizar'),
                Action::make('download_resume')
                    ->label('Baixar Currículo')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (JobApplication $record): string => route('admin.job-applications.resume', $record))
                    ->visible(fn (JobApplication $record): bool => (bool) $record->resume_path),
            ])
            ->bulkActions([
                BulkAction::make('bulk_change_status')
                    ->label('Alterar Status em Lote')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('primary')
                    ->form([
                        Select::make('status')
                            ->label('Novo Status')
                            ->options(JobApplication::statusOptions())
                            ->required()
                            ->native(false),
                        Textarea::make('note')
                            ->label('Observação (opcional)')
                            ->rows(2),
                    ])
                    ->authorize(fn (): bool => Gate::allows('update', JobApplication::class))
                    ->action(function (Collection $records, array $data): void {
                        $count = 0;
                        $skipped = 0;

                        foreach ($records as $record) {
                            $changed = JobApplicationStatusService::changeStatus($record, $data['status'], $data['note'] ?? null);
                            if ($changed) {
                                $count++;
                                if (in_array($data['status'], [JobApplication::STATUS_HIRED, JobApplication::STATUS_REJECTED], true)) {
                                    SendJobApplicationStatusMail::dispatch($record->fresh(['vacancy']));
                                }
                            } else {
                                $skipped++;
                            }
                        }

                        Notification::make()
                            ->title($count.' candidatura(s) movida(s).'.($skipped ? ' '.$skipped.' já estavam no status.' : ''))
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('export')
                    ->label('Exportar CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Collection $records): StreamedResponse {
                        $filename = 'candidaturas-'.now()->format('Y-m-d-His').'.csv';

                        return response()->streamDownload(function () use ($records): void {
                            $handle = fopen('php://output', 'w');
                            fputcsv($handle, ['Candidatura ID', 'Vaga', 'Candidato', 'E-mail', 'Telefone', 'LinkedIn', 'Status', 'Avaliada por', 'Avaliada em', 'Recebida em'], ';');
                            foreach ($records as $r) {
                                $r->loadMissing(['vacancy', 'reviewedBy']);
                                fputcsv($handle, [
                                    $r->id,
                                    $r->vacancy?->title ?? '—',
                                    $r->name,
                                    $r->email,
                                    $r->phone,
                                    $r->linkedin_url ?? '—',
                                    JobApplication::statusLabelFor($r->status),
                                    $r->reviewedBy?->name ?? '—',
                                    $r->reviewed_at?->format('d/m/Y H:i') ?? '—',
                                    $r->created_at?->format('d/m/Y H:i'),
                                ], ';');
                            }
                            fclose($handle);
                        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
                    }),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Exportar Filtradas')
                    ->exporter(JobApplicationExporter::class)
                    ->authorize(fn (): bool => Gate::allows('viewAny', JobApplication::class)),
            ])
            ->emptyStateHeading('Nenhuma candidatura encontrada')
            ->emptyStateDescription('Não há candidaturas que correspondam aos filtros atuais. Ajuste os filtros ou aguarde novas inscrições.')
            ->emptyStateIcon('heroicon-o-user-group')
            ->defaultSort('created_at', 'desc');
    }
}
