<?php

$content = file_get_contents('app/Filament/Resources/Emissions/EmissionResource/RelationManagers/ObligationsRelationManager.php');

$newInfolist = <<<PHP

    public function infolist(\Filament\Infolists\Infolist \$infolist): \Filament\Infolists\Infolist
    {
        return \$infolist->schema([
            \Filament\Infolists\Components\Section::make('Dossiê da Obrigação')
                ->schema([
                    \Filament\Infolists\Components\Grid::make(4)->schema([
                        \Filament\Infolists\Components\TextEntry::make('title')
                            ->label('Obrigação')
                            ->weight('bold')
                            ->size('lg')
                            ->columnSpan(2),
                        \Filament\Infolists\Components\TextEntry::make('status')
                            ->label('Status Atual')
                            ->badge()
                            ->formatStateUsing(fn (?string \$state): string => \App\Models\Obligation::STATUS_OPTIONS[\$state] ?? (string) \$state)
                            ->color(fn (?string \$state): string => match (\$state) {
                                'em_dia', 'concluida' => 'success',
                                'a_vencer' => 'info',
                                'vencida' => 'danger',
                                'em_analise' => 'warning',
                                default => 'gray',
                            }),
                        \Filament\Infolists\Components\TextEntry::make('priority')
                            ->label('Prioridade')
                            ->badge()
                            ->formatStateUsing(fn (?string \$state): string => \App\Models\Obligation::PRIORITY_OPTIONS[\$state] ?? (string) \$state)
                            ->color(fn (?string \$state): string => match (\$state) {
                                'critical' => 'danger',
                                'high' => 'warning',
                                'medium' => 'info',
                                default => 'gray',
                            }),
                        \Filament\Infolists\Components\TextEntry::make('due_date')
                            ->label('Prazo / Vencimento')
                            ->date('d/m/Y')
                            ->placeholder('Sem prazo definido'),
                        \Filament\Infolists\Components\TextEntry::make('responsibleUser.name')
                            ->label('Responsável')
                            ->placeholder('Não atribuído'),
                        \Filament\Infolists\Components\TextEntry::make('responsible_area')
                            ->label('Área Responsável')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('source')
                            ->label('Origem')
                            ->state(fn (\App\Models\Obligation \$record): string => \$record->extracted_obligation_id !== null ? 'Gerada pelo Termo' : 'Manual')
                            ->placeholder('—'),
                    ]),
                    \Filament\Infolists\Components\TextEntry::make('description')
                        ->label('Descrição / Detalhes da Obrigação')
                        ->columnSpanFull()
                        ->placeholder('Sem descrição adicional.'),
                ]),
        ]);
    }
PHP;

$content = preg_replace('/public function form\(Schema \$schema\): Schema\s*\{.*?\n    \}/s', "public function form(Schema \$schema): Schema\n    {\n        return \$schema->schema(ObligationFormFields::make('obligation'))->columns(2);\n    }\n$newInfolist", $content);

$newActions = <<<PHP
                \Filament\Tables\Actions\ViewAction::make()
                    ->label('Acessar Dossiê')
                    ->color('info')
                    ->authorize(fn (): bool => auth()->user()?->can(\App\Enums\AccessPermission::ObligationsView->value) ?? false),
                \$this->makeCommentsAction(),
PHP;

$content = str_replace('$this->makeCommentsAction(),', $newActions, $content);

file_put_contents('app/Filament/Resources/Emissions/EmissionResource/RelationManagers/ObligationsRelationManager.php', $content);
echo "ObligationsRelationManager updated.\n";
