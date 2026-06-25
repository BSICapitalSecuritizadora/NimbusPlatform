<?php

$content = file_get_contents('app/Filament/Resources/Proposals/ProposalResource.php');

$newInfolist = <<<'PHP'
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dossiê Executivo')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('company.name')
                            ->label('Proponente')
                            ->weight('bold')
                            ->size('lg'),
                        TextEntry::make('company.cnpj')
                            ->label('CNPJ'),
                        TextEntry::make('status')
                            ->label('Situação Atual')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => ProposalStatus::labelFor($state))
                            ->color(fn (?string $state): string => ProposalStatus::colorFor($state)),
                        TextEntry::make('representative.name')
                            ->label('Responsável')
                            ->placeholder('Não atribuído'),
                        TextEntry::make('created_at')
                            ->label('Data de Entrada')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestStatusHistory.changed_at')
                            ->label('Última Atualização')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('source')
                            ->label('Origem')
                            ->state('Captação via Site')
                            ->placeholder('—'),
                        TextEntry::make('next_action')
                            ->label('Próxima Ação Recomendada')
                            ->state(fn (?Proposal $record): string => match ($record?->status) {
                                'aguardando_complementacao' => 'Aguardar o cliente complementar as informações pendentes.',
                                'em_analise' => 'Analisar a documentação e aprovar ou solicitar mais informações.',
                                'aguardando_informacoes' => 'Aguardar o cliente enviar as informações adicionais solicitadas.',
                                'aprovado' => 'Prosseguir com a emissão do contrato/formalização.',
                                'rejeitado' => 'Nenhuma ação. Proposta arquivada.',
                                'concluida' => 'Nenhuma ação. Processo de proposta concluído.',
                                default => 'Definir responsável e iniciar análise.',
                            })
                            ->color('primary')
                            ->weight('bold'),
                    ]),
                ]),

            Grid::make(2)->schema([
                Section::make('Resumo da Proposta')
                    ->schema([
                        TextEntry::make('observations')
                            ->label('Informações Complementares')
                            ->placeholder('Sem observações.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Contato Responsável')
                    ->schema([
                        TextEntry::make('contact.name')
                            ->label('Nome do Contato')
                            ->placeholder('—'),
                        TextEntry::make('contact.email')
                            ->label('E-mail')
                            ->placeholder('—'),
                        TextEntry::make('contact.phone_summary')
                            ->label('Telefones')
                            ->placeholder('—'),
                        TextEntry::make('contact.cargo')
                            ->label('Cargo')
                            ->placeholder('—'),
                    ])->columns(2),
                Section::make('Dados da Empresa')
                    ->schema([
                        TextEntry::make('company.ie')
                            ->label('Inscrição Estadual (IE)')
                            ->placeholder('—'),
                        TextEntry::make('company.site')
                            ->label('Site Institucional')
                            ->placeholder('—')
                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                            ->openUrlInNewTab(),
                        TextEntry::make('company.full_address')
                            ->label('Endereço Completo')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('company.sectors.name')
                            ->label('Setores de Atuação')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])->columns(2),
                Section::make('Fluxo e Andamento')
                    ->schema([
                        TextEntry::make('distribution_sequence')
                            ->label('Ordem na Fila')
                            ->numeric(decimalPlaces: 0)
                            ->placeholder('—'),
                        TextEntry::make('distributed_at')
                            ->label('Data de Distribuição')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('completed_at')
                            ->label('Data de Formalização')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ])->columns(2),
                Section::make('Histórico / Atividades')
                    ->schema([
                        TextEntry::make('latest_status_changed_by')
                            ->label('Última Atualização por')
                            ->state(fn (?Proposal $record): ?string => match (true) {
                                ! $record?->latestStatusHistory => null,
                                (bool) $record->latestStatusHistory->changedByUser?->name => $record->latestStatusHistory->changedByUser->name,
                                default => 'Sistema',
                            })
                            ->placeholder('—'),
                        TextEntry::make('latestStatusHistory.note')
                            ->label('Histórico de Observações')
                            ->placeholder('Sem observação registrada.')
                            ->columnSpanFull(),
                        TextEntry::make('internal_notes')
                            ->label('Informações Complementares Internas')
                            ->placeholder('Sem observações internas.')
                            ->columnSpanFull(),
                    ])->columns(2),
                Section::make('Link Seguro (Acesso do Cliente)')
                    ->schema([
                        TextEntry::make('latestContinuationAccess.status_label')
                            ->label('Situação do Acesso')
                            ->state(fn (Proposal $record): ?string => $record->latestContinuationAccess?->status_label)
                            ->placeholder('—')
                            ->badge()
                            ->color(fn (Proposal $record): string => $record->latestContinuationAccess?->status_color ?? 'gray'),
                        TextEntry::make('latestContinuationAccess.display_code')
                            ->label('Código de Acesso')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('latestContinuationAccess.sent_at')
                            ->label('Data de Envio')
                            ->state(fn (Proposal $record) => $record->latestContinuationAccess?->sent_at ?? $record->latestContinuationAccess?->created_at)
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestContinuationAccess.expires_at')
                            ->label('Data de Expiração')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestContinuationAccess.generated_url')
                            ->label('Link Gerado')
                            ->placeholder('—')
                            ->copyable()
                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                            ->openUrlInNewTab()
                            ->columnSpanFull(),
                    ])->columns(2)->collapsed(),
            ]),
        ]);
    }
PHP;

$content = preg_replace('/public static function infolist\(Schema \$schema\): Schema\s*\{.*?\n    \}/s', $newInfolist, $content);

file_put_contents('app/Filament/Resources/Proposals/ProposalResource.php', $content);
echo "Infolist updated successfully.\n";
