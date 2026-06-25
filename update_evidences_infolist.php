<?php

$content = file_get_contents('app/Filament/Resources/Emissions/EmissionResource/RelationManagers/ObligationEvidencesRelationManager.php');

$newInfolist = <<<PHP

    public function infolist(\Filament\Infolists\Infolist \$infolist): \Filament\Infolists\Infolist
    {
        return \$infolist->schema([
            \Filament\Infolists\Components\Section::make('Revisão da Evidência')
                ->schema([
                    \Filament\Infolists\Components\Grid::make(3)->schema([
                        \Filament\Infolists\Components\TextEntry::make('original_name')
                            ->label('Arquivo Anexado')
                            ->weight('bold')
                            ->size('lg')
                            ->columnSpan(2),
                        \Filament\Infolists\Components\TextEntry::make('status')
                            ->label('Status da Revisão')
                            ->badge()
                            ->formatStateUsing(fn (?string \$state): string => \App\Models\ObligationEvidence::STATUS_OPTIONS[\$state] ?? (string) \$state)
                            ->color(fn (?string \$state): string => match (\$state) {
                                \App\Models\ObligationEvidence::STATUS_APPROVED => 'success',
                                \App\Models\ObligationEvidence::STATUS_REJECTED => 'danger',
                                default => 'warning',
                            }),
                        \Filament\Infolists\Components\TextEntry::make('uploader.name')
                            ->label('Enviado por')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('uploaded_at')
                            ->label('Data de Envio')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('size')
                            ->label('Tamanho do Arquivo')
                            ->formatStateUsing(fn (?int \$state): string => \$state ? \Illuminate\Support\Number::fileSize(\$state) : '—'),
                    ]),
                    \Filament\Infolists\Components\TextEntry::make('description')
                        ->label('Descrição do Upload')
                        ->columnSpanFull()
                        ->placeholder('Sem descrição informada.'),
                ]),
            \Filament\Infolists\Components\Section::make('Notas de Revisão')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('reviewer.name')
                        ->label('Revisado por')
                        ->placeholder('Ainda não revisado.'),
                    \Filament\Infolists\Components\TextEntry::make('reviewed_at')
                        ->label('Data da Revisão')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                    \Filament\Infolists\Components\TextEntry::make('review_notes')
                        ->label('Observações de Aprovação')
                        ->visible(fn (\App\Models\ObligationEvidence \$record) => \$record->status === \App\Models\ObligationEvidence::STATUS_APPROVED)
                        ->placeholder('Nenhuma observação informada.'),
                    \Filament\Infolists\Components\TextEntry::make('rejection_reason')
                        ->label('Motivo da Rejeição')
                        ->color('danger')
                        ->visible(fn (\App\Models\ObligationEvidence \$record) => \$record->status === \App\Models\ObligationEvidence::STATUS_REJECTED)
                        ->placeholder('—'),
                ])->columns(2),
        ]);
    }
PHP;

$content = preg_replace('/public function table\(Table \$table\): Table\s*\{/s', "$newInfolist\n\n    public function table(Table \$table): Table\n    {", $content);

$newActions = <<<PHP
                \Filament\Tables\Actions\ViewAction::make()
                    ->label('Revisar Evidência')
                    ->color('info')
                    ->authorize(fn (): bool => auth()->user()?->can(\App\Enums\AccessPermission::ObligationsViewEvidence->value) ?? false),
                \$this->makeApproveAction(),
PHP;

$content = str_replace('$this->makeApproveAction(),', $newActions, $content);

file_put_contents('app/Filament/Resources/Emissions/EmissionResource/RelationManagers/ObligationEvidencesRelationManager.php', $content);
echo "ObligationEvidencesRelationManager updated.\n";
