<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Jobs\GenerateEmissionObligationsJob;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ObligationSuggestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'extractedObligations';

    protected static ?string $title = 'Obrigações Sugeridas';

    protected static ?string $modelLabel = 'Sugestão';

    protected static ?string $pluralModelLabel = 'Obrigações Sugeridas';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('obligations.view') ?? false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Emission) {
            return null;
        }

        $pending = $ownerRecord->extractedObligations()->where('status', 'suggested')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(ObligationFormFields::make('suggestion'))->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->description('Revise as obrigações sugeridas pela IA a partir do Termo de Securitização e aprove para consolidá-las.')
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('obligation_category')
                    ->label('Categoria')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('responsibleUser.name')
                    ->label('Responsável')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('due_rule')
                    ->label('Prazo')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ExtractedObligation::PRIORITY_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('confidence_score')
                    ->label('Confiança')
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : round($state * 100).'%')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ExtractedObligation::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ExtractedObligation::STATUS_OPTIONS),
            ])
            ->headerActions([
                $this->makeGenerateAction(),
            ])
            ->actions([
                $this->makeApproveAction(),
                $this->makeRejectAction(),
                EditAction::make()
                    ->label('Editar')
                    ->authorize(fn (): bool => $this->canManage()),
                DeleteAction::make()
                    ->authorize(fn (): bool => $this->canManage()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => $this->canManage()),
                ]),
            ])
            ->emptyStateHeading('Nenhuma obrigação sugerida')
            ->emptyStateDescription('Use "Gerar obrigações do Termo" para extrair sugestões do Termo de Securitização.');
    }

    protected function makeGenerateAction(): Action
    {
        return Action::make('generate_obligations')
            ->label('Gerar obrigações do Termo')
            ->icon('heroicon-o-sparkles')
            ->color('warning')
            ->authorize(fn (): bool => $this->canManage())
            ->requiresConfirmation()
            ->modalHeading('Gerar obrigações do Termo de Securitização')
            ->modalDescription('A IA analisará o Termo de Securitização e gerará obrigações sugeridas. O processo pode levar alguns minutos. As sugestões pendentes anteriores serão substituídas.')
            ->modalSubmitActionLabel('Iniciar geração')
            ->action(function (): void {
                $document = $this->findSecuritizationTerm();

                if ($document === null) {
                    Notification::make()
                        ->title('Termo de Securitização não encontrado')
                        ->body('Adicione o documento na seção "Documentos da Operação" com o título exato "Termo de Securitização".')
                        ->warning()
                        ->send();

                    return;
                }

                GenerateEmissionObligationsJob::dispatch($this->getOwnerRecord()->id, $document->id);

                Notification::make()
                    ->title('Geração de obrigações iniciada')
                    ->body('As sugestões aparecerão nesta aba assim que o processamento for concluído. Atualize a página em alguns minutos.')
                    ->info()
                    ->send();
            });
    }

    protected function makeApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprovar')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (ExtractedObligation $record): bool => $record->status === 'suggested' && $this->canManage())
            ->requiresConfirmation()
            ->modalHeading('Aprovar obrigação sugerida')
            ->modalDescription('A sugestão será consolidada como uma obrigação da emissão.')
            ->action(function (ExtractedObligation $record): void {
                if ($record->obligation()->exists()) {
                    Notification::make()
                        ->title('Sugestão já consolidada')
                        ->warning()
                        ->send();

                    return;
                }

                $this->getOwnerRecord()->obligations()->create(
                    ObligationFormFields::mapSuggestionToObligation($record),
                );

                $record->update([
                    'status' => 'approved',
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                ]);

                Notification::make()
                    ->title('Obrigação aprovada e consolidada')
                    ->success()
                    ->send();
            });
    }

    protected function makeRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Rejeitar')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (ExtractedObligation $record): bool => $record->status === 'suggested' && $this->canManage())
            ->requiresConfirmation()
            ->modalHeading('Rejeitar obrigação sugerida')
            ->action(function (ExtractedObligation $record): void {
                $record->update([
                    'status' => 'rejected',
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                ]);

                Notification::make()
                    ->title('Sugestão rejeitada')
                    ->success()
                    ->send();
            });
    }

    protected function findSecuritizationTerm(): ?\App\Models\Document
    {
        return $this->getOwnerRecord()->documents()
            ->where('category', 'documentos_operacao')
            ->whereRaw('TRIM(title) = ?', ['Termo de Securitização'])
            ->first();
    }

    protected function canManage(): bool
    {
        return auth()->user()?->can('obligations.create') ?? false;
    }
}
