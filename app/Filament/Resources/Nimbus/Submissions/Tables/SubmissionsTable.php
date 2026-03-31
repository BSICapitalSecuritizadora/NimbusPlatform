<?php

namespace App\Filament\Resources\Nimbus\Submissions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nimbus_portal_user_id')
                    ->label('Usuário do portal Nimbus')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reference_code')
                    ->label('Código de referência')
                    ->searchable(),
                TextColumn::make('submission_type')
                    ->label('Tipo de envio')
                    ->searchable(),
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable(),
                TextColumn::make('responsible_name')
                    ->label('Nome do responsável')
                    ->searchable(),
                TextColumn::make('company_cnpj')
                    ->label('CNPJ da empresa')
                    ->searchable(),
                TextColumn::make('company_name')
                    ->label('Empresa')
                    ->searchable(),
                TextColumn::make('main_activity')
                    ->label('Atividade principal')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->searchable(),
                TextColumn::make('website')
                    ->label('Site')
                    ->searchable(),
                TextColumn::make('net_worth')
                    ->label('Patrimônio líquido')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('annual_revenue')
                    ->label('Faturamento anual')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_us_person')
                    ->label('Pessoa dos EUA')
                    ->boolean(),
                IconColumn::make('is_pep')
                    ->label('PEP')
                    ->boolean(),
                TextColumn::make('registrant_name')
                    ->label('Nome do cadastrante')
                    ->searchable(),
                TextColumn::make('registrant_position')
                    ->label('Cargo do cadastrante')
                    ->searchable(),
                TextColumn::make('registrant_rg')
                    ->label('RG do cadastrante')
                    ->searchable(),
                TextColumn::make('registrant_cpf')
                    ->label('CPF do cadastrante')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('created_ip')
                    ->label('IP de criação')
                    ->searchable(),
                TextColumn::make('created_user_agent')
                    ->label('Navegador / dispositivo')
                    ->searchable(),
                TextColumn::make('submitted_at')
                    ->label('Enviado em')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status_updated_at')
                    ->label('Status atualizado em')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status_updated_by')
                    ->label('Atualizado por')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Excluir selecionados'),
                ]),
            ]);
    }
}
