<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Jobs\GenerateEmissionObligationsJob;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\ObligationGenerationRun;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class ObligationSuggestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'extractedObligations';

    protected static ?string $title = 'Obrigações Sugeridas';

    protected static ?string $modelLabel = 'Sugestão';

    protected static ?string $pluralModelLabel = 'Obrigações Sugeridas';

    protected ?ObligationGenerationRun $generationRunCache = null;

    protected bool $generationRunResolved = false;

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
            ->poll($this->shouldPollGeneration() ? '4s' : null)
            ->description(fn (): string|Htmlable => $this->generationDescription())
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
            ->defaultSort('confidence_score', 'desc')
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
            ->disabled(fn (): bool => $this->hasActiveGenerationRun())
            ->requiresConfirmation()
            ->modalHeading('Gerar obrigações do Termo de Securitização')
            ->modalDescription('A IA analisará o Termo de Securitização e gerará obrigações sugeridas. O processo pode levar alguns minutos. As sugestões pendentes anteriores serão substituídas.')
            ->modalSubmitActionLabel('Iniciar geração')
            ->action(function (): void {
                if ($this->hasActiveGenerationRun()) {
                    Notification::make()
                        ->title('Geração já em andamento')
                        ->body('Aguarde a conclusão da geração atual antes de iniciar uma nova.')
                        ->warning()
                        ->send();

                    return;
                }

                $document = $this->findSecuritizationTerm();

                if ($document === null) {
                    Notification::make()
                        ->title('Termo de Securitização não encontrado')
                        ->body('Adicione o documento na seção "Documentos da Operação" com o título exato "Termo de Securitização".')
                        ->warning()
                        ->send();

                    return;
                }

                $run = ObligationGenerationRun::create([
                    'emission_id' => $this->getOwnerRecord()->id,
                    'document_id' => $document->id,
                    'user_id' => auth()->id(),
                    'status' => ObligationGenerationRun::STATUS_PENDING,
                    'current_step' => 'queued',
                    'message' => 'Preparando leitura do Termo...',
                ]);

                GenerateEmissionObligationsJob::dispatch($this->getOwnerRecord()->id, $document->id, $run->id);

                Notification::make()
                    ->title('Geração de obrigações iniciada')
                    ->body('Acompanhe o progresso nesta aba. As sugestões aparecerão automaticamente ao concluir.')
                    ->info()
                    ->send();
            });
    }

    protected function latestGenerationRun(): ?ObligationGenerationRun
    {
        if ($this->generationRunResolved) {
            return $this->generationRunCache;
        }

        $this->generationRunResolved = true;

        return $this->generationRunCache = $this->getOwnerRecord()
            ->latestObligationGenerationRun()
            ->first();
    }

    protected function hasActiveGenerationRun(): bool
    {
        return $this->latestGenerationRun()?->isActive() ?? false;
    }

    protected function shouldPollGeneration(): bool
    {
        return $this->hasActiveGenerationRun();
    }

    protected function generationDescription(): string|Htmlable
    {
        $run = $this->latestGenerationRun();

        $isDisplayable = $run !== null && (
            $run->isActive()
            || $run->hasFailed()
            || ($run->isCompleted() && $run->finished_at?->gt(now()->subMinutes(10)))
        );

        $banner = $isDisplayable
            ? view('filament.obligations.generation-progress', ['run' => $run])->render()
            : '';

        return new HtmlString(
            $banner.'<span class="block">Revise as obrigações sugeridas pela IA a partir do Termo de Securitização e aprove para consolidá-las.</span>'
        );
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
