<?php

namespace App\Filament\Resources\ContactMessages;

use App\Filament\Resources\ContactMessages\Pages\EditContactMessage;
use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessages\Pages\ViewContactMessage;
use App\Filament\Resources\ContactMessages\Tables\ContactMessagesTable;
use App\Models\ContactMessage;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Mensagens de Contato';

    protected static ?string $modelLabel = 'Mensagem';

    protected static ?string $pluralModelLabel = 'Mensagens de Contato';

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        $count = ContactMessage::query()->where('status', ContactMessage::STATUS_NEW)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return ContactMessage::query()->where('status', ContactMessage::STATUS_NEW)->exists() ? 'warning' : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('attendedBy');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Atendimento')
                ->schema([
                    Select::make('status')
                        ->label('Status')
                        ->options(ContactMessage::statusOptions())
                        ->required()
                        ->native(false),
                    Placeholder::make('received_at')
                        ->label('Recebida em')
                        ->content(fn (?ContactMessage $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('attended_by_name')
                        ->label('Atendido por')
                        ->content(fn (?ContactMessage $record): string => $record?->attendedBy?->name ?? '—'),
                    Textarea::make('internal_notes')
                        ->label('Observações Internas')
                        ->placeholder('Registre aqui o histórico de atendimento, respostas enviadas e pendências.')
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Mensagem Recebida')
                ->schema([
                    Placeholder::make('subject_display')
                        ->label('Assunto')
                        ->content(fn (?ContactMessage $record): string => $record?->subject ?? '—'),
                    Placeholder::make('name_display')
                        ->label('Nome')
                        ->content(fn (?ContactMessage $record): string => $record?->name ?? '—'),
                    Placeholder::make('email_display')
                        ->label('E-mail')
                        ->content(fn (?ContactMessage $record): string => $record?->email ?? '—'),
                    Placeholder::make('phone_display')
                        ->label('Telefone')
                        ->content(fn (?ContactMessage $record): string => $record?->phone ?? '—'),
                    Placeholder::make('message_display')
                        ->label('Mensagem')
                        ->content(fn (?ContactMessage $record): string => $record?->message ?? '—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Atendimento')
                ->schema([
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => ContactMessage::statusLabelFor($state))
                        ->color(fn (?string $state): string => ContactMessage::statusColorFor($state)),
                    TextEntry::make('created_at')->label('Recebida em')->dateTime('d/m/Y H:i'),
                    TextEntry::make('attendedBy.name')->label('Atendido por')->placeholder('—'),
                    TextEntry::make('attended_at')->label('Atendido em')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('internal_notes')
                        ->label('Observações Internas')
                        ->placeholder('Sem observações registradas.')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Mensagem Recebida')
                ->schema([
                    TextEntry::make('subject')->label('Assunto'),
                    TextEntry::make('name')->label('Nome'),
                    TextEntry::make('email')->label('E-mail'),
                    TextEntry::make('phone')->label('Telefone')->placeholder('—'),
                    TextEntry::make('message')->label('Mensagem')->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ContactMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactMessages::route('/'),
            'view' => ViewContactMessage::route('/{record}'),
            'edit' => EditContactMessage::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
