<?php

namespace App\Filament\Resources\Recruitment\Tables;

use App\Enums\MalwareScanStatus;
use App\Filament\Exports\JobApplicationExporter;
use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Filament\Resources\Recruitment\VacancyResource;
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
use Filament\Support\Enums\Width;
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
            ->searchPlaceholder('Buscar por candidato, vaga ou e-mail...')
            ->searchDebounce('400ms')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('name')
                    ->label('Candidato')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (JobApplication $record): ?string => $record->email ?: null),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => JobApplication::statusLabelFor($state))
                    ->color(fn (?string $state): string => JobApplication::statusColorFor($state))
                    ->sortable(),

                TextColumn::make('vacancy.title')
                    ->label('Vaga')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->placeholder('—'),

                TextColumn::make('reviewed_at')
                    ->label('Última Movimentação')
                    ->dateTime('d/m/Y · H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->description(fn (JobApplication $record): ?string => $record->reviewedBy?->name ? 'Por: '.$record->reviewedBy->name : null),

                TextColumn::make('created_at')
                    ->label('Recebida em')
                    ->dateTime('d/m/Y · H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-slate-400'])
                    ->toggleable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(JobApplication::statusOptions())
                    ->placeholder('Todos')
                    ->native(true),
                SelectFilter::make('vacancy_id')
                    ->label('Vaga')
                    ->relationship('vacancy', 'title')
                    ->placeholder('Todas')
                    ->native(true),
                SelectFilter::make('scan_status')
                    ->label('Antivírus')
                    ->options([
                        MalwareScanStatus::Pending->value => 'Pendente',
                        MalwareScanStatus::Clean->value => 'Limpo',
                        MalwareScanStatus::Infected->value => 'Infectado',
                    ])
                    ->placeholder('Todos')
                    ->native(true),
                Filter::make('created_at')
                    ->columns(2)
                    ->form([
                        DatePicker::make('created_from')
                            ->label('Recebida de')
                            ->placeholder('dd/mm/aaaa')
                            ->displayFormat('d/m/Y')
                            ->native(false),
                        DatePicker::make('created_until')
                            ->label('Recebida até')
                            ->placeholder('dd/mm/aaaa')
                            ->displayFormat('d/m/Y')
                            ->native(false),
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
            ->filtersFormColumns(1)
            ->filtersFormWidth(Width::Large)
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
                    ->label('Alterar status em lote')
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
                    ->color('gray')
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
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->exporter(JobApplicationExporter::class)
                    ->authorize(fn (): bool => Gate::allows('viewAny', JobApplication::class)),
            ])
            ->emptyStateHeading('Nenhuma candidatura encontrada')
            ->emptyStateDescription('Não há inscrições correspondentes aos critérios ou à etapa selecionada. Ajuste os filtros aplicados ou confira as oportunidades cadastradas.')
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateActions([
                Action::make('view_vacancies')
                    ->label('Gerenciar Vagas')
                    ->icon('heroicon-o-briefcase')
                    ->color('primary')
                    ->url(fn (): string => VacancyResource::getUrl('index')),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
