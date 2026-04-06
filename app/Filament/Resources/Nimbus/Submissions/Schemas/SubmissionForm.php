<?php

namespace App\Filament\Resources\Nimbus\Submissions\Schemas;

use App\Models\Nimbus\Submission;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nimbus_portal_user_id')
                    ->label('ID do Usuário do Portal Nimbus')
                    ->required()
                    ->numeric(),
                TextInput::make('reference_code')
                    ->label('Código de Referência')
                    ->required(),
                TextInput::make('submission_type')
                    ->label('Tipo de Envio')
                    ->required()
                    ->default('REGISTRATION'),
                TextInput::make('title')
                    ->label('Assunto')
                    ->required(),
                Textarea::make('message')
                    ->label('Mensagem')
                    ->columnSpanFull(),
                TextInput::make('responsible_name')
                    ->label('Nome do Responsável'),
                TextInput::make('company_cnpj')
                    ->label('CNPJ da Empresa'),
                TextInput::make('company_name')
                    ->label('Razão Social / Empresa'),
                TextInput::make('main_activity')
                    ->label('Atividade Principal'),
                TextInput::make('phone')
                    ->label('Telefone')
                    ->tel(),
                TextInput::make('website')
                    ->label('Website')
                    ->url(),
                TextInput::make('net_worth')
                    ->label('Patrimônio Líquido')
                    ->numeric(),
                TextInput::make('annual_revenue')
                    ->label('Faturamento Anual')
                    ->numeric(),
                Toggle::make('is_us_person')
                    ->label('US Person?')
                    ->required(),
                Toggle::make('is_pep')
                    ->label('Pessoa Exposta (PEP)?')
                    ->required(),
                TextInput::make('shareholder_data')
                    ->label('Dados Societários'),
                TextInput::make('registrant_name')
                    ->label('Nome do Cadastrante'),
                TextInput::make('registrant_position')
                    ->label('Cargo do Cadastrante'),
                TextInput::make('registrant_rg')
                    ->label('RG do Cadastrante'),
                TextInput::make('registrant_cpf')
                    ->label('CPF do Cadastrante'),
                Select::make('status')
                    ->label('Situação Atual')
                    ->options(Submission::statusOptions())
                    ->default(Submission::STATUS_PENDING)
                    ->required(),
                TextInput::make('created_ip')
                    ->label('IP de Criação'),
                TextInput::make('created_user_agent')
                    ->label('Dispositivo/Navegador'),
                DateTimePicker::make('submitted_at')
                    ->label('Data e Hora de Envio')
                    ->required(),
                DateTimePicker::make('status_updated_at')
                    ->label('Situação Atualizada Em'),
                TextInput::make('status_updated_by')
                    ->label('Atualizado Por (ID)')
                    ->numeric(),
            ]);
    }
}
