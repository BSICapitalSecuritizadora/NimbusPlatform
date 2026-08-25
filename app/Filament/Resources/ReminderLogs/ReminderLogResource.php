<?php

namespace App\Filament\Resources\ReminderLogs;

use App\Enums\AccessPermission;
use App\Models\ReminderLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

class ReminderLogResource extends Resource
{
    protected static ?string $model = ReminderLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $navigationParentItem = 'Auditoria';

    protected static ?int $navigationSort = 22;

    protected static ?string $modelLabel = 'Auditoria de Lembrete';

    protected static ?string $pluralModelLabel = 'Auditoria de Lembretes';

    protected static ?string $navigationLabel = 'Auditoria de Lembretes';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('status')
                    ->label('Resultado do Envio')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyStatus($state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),

                TextEntry::make('recipient_email')
                    ->label('Destinatário')
                    ->placeholder('—')
                    ->weight(FontWeight::SemiBold),

                TextEntry::make('sent_at')
                    ->label('Data e Hora de Envio')
                    ->dateTime('d/m/Y · H:i:s')
                    ->fontFamily(FontFamily::Mono),

                TextEntry::make('type')
                    ->label('Tipo de Notificação')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyType($state))
                    ->helperText(fn (?string $state): ?string => $state ? "Classe técnica: {$state}" : null)
                    ->badge()
                    ->color('gray'),

                TextEntry::make('channel')
                    ->label('Canal')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyChannel($state))
                    ->icon(fn (?string $state): ?string => self::channelIcon($state))
                    ->badge()
                    ->color('gray'),

                TextEntry::make('severity')
                    ->label('Criticidade')
                    ->formatStateUsing(fn (?string $state): string => self::friendlySeverity($state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'warning', 'atencao' => 'warning',
                        'danger', 'critical', 'critico', 'high' => 'danger',
                        default => 'gray',
                    }),

                TextEntry::make('reason')
                    ->label('Motivo do Envio')
                    ->placeholder('—')
                    ->columnSpanFull(),

                TextEntry::make('error_message')
                    ->label('Mensagem de Erro')
                    ->placeholder('Nenhum erro reportado.')
                    ->fontFamily(FontFamily::Mono)
                    ->visible(fn (ReminderLog $record): bool => ! empty($record->error_message))
                    ->columnSpanFull(),

                TextEntry::make('payload')
                    ->label('Dados do Payload (JSON)')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->fontFamily(FontFamily::Mono)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('Buscar por destinatário, tipo ou motivo...')
            ->defaultSort('sent_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->recordUrl(null)
            ->columns([
                TextColumn::make('sent_at')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y · H:i:s')
                    ->fontFamily(FontFamily::Mono)
                    ->tooltip(fn (ReminderLog $record): ?string => $record->sent_at?->diffForHumans())
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyType($state))
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('channel')
                    ->label('Canal')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyChannel($state))
                    ->icon(fn (?string $state): ?string => self::channelIcon($state))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('recipient_email')
                    ->label('Destinatário')
                    ->weight(FontWeight::SemiBold)
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyStatus($state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('severity')
                    ->label('Criticidade')
                    ->formatStateUsing(fn (?string $state): string => self::friendlySeverity($state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'warning', 'atencao' => 'warning',
                        'danger', 'critical', 'critico', 'high' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->placeholder('—')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();

                        return $state && strlen($state) > 40 ? $state : null;
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'sent' => 'Enviado',
                        'failed' => 'Falhou',
                        'pending' => 'Pendente',
                        'ignored' => 'Ignorado',
                    ]),

                SelectFilter::make('channel')
                    ->label('Canal')
                    ->options([
                        'database' => 'Sistema',
                        'mail' => 'E-mail',
                        'sms' => 'SMS',
                    ]),

                SelectFilter::make('severity')
                    ->label('Criticidade')
                    ->options([
                        'info' => 'Informativa',
                        'warning' => 'Atenção',
                        'danger' => 'Crítica',
                    ]),

                Filter::make('sent_at')
                    ->label('Data de Envio')
                    ->form([
                        DatePicker::make('from')->label('Data Inicial'),
                        DatePicker::make('until')->label('Data Final'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('sent_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('sent_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Visualizar')
                    ->tooltip('Visualizar registro de lembrete')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->iconButton(),
            ])
            ->emptyStateHeading('Nenhum lembrete auditado')
            ->emptyStateDescription('Ainda não há registros de processamento para os critérios selecionados.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageReminderLogs::route('/'),
        ];
    }

    public static function friendlyType(?string $type): string
    {
        if (blank($type)) {
            return '—';
        }

        $base = class_basename($type);

        return match ($base) {
            'DatabaseNotification' => 'Notificação no sistema',
            'MeasurementWorkflowNotification' => 'Fluxo de Medição',
            'VerifyEmail', 'VerifyEmailNotification' => 'Verificação de E-mail',
            'ResetPassword', 'ResetPasswordNotification' => 'Redefinição de Senha',
            'ProposalStatusNotification' => 'Atualização de Proposta',
            'ContactReceivedNotification' => 'Mensagem de Contato',
            'PaymentReminderNotification' => 'Lembrete de Pagamento',
            'DocumentExpiringNotification' => 'Vencimento de Documento',
            default => Str::headline($base),
        };
    }

    public static function friendlyChannel(?string $channel): string
    {
        if (blank($channel)) {
            return '—';
        }

        return match ($channel) {
            'database', 'system' => 'Sistema',
            'mail', 'email' => 'E-mail',
            'sms' => 'SMS',
            'whatsapp' => 'WhatsApp',
            default => Str::headline($channel),
        };
    }

    public static function channelIcon(?string $channel): ?string
    {
        return match ($channel) {
            'database', 'system' => 'heroicon-m-bell',
            'mail', 'email' => 'heroicon-m-envelope',
            'sms' => 'heroicon-m-device-phone-mobile',
            'whatsapp' => 'heroicon-m-chat-bubble-left-ellipsis',
            default => 'heroicon-m-paper-airplane',
        };
    }

    public static function friendlyStatus(?string $status): string
    {
        if (blank($status)) {
            return '—';
        }

        return match ($status) {
            'sent' => 'Enviado',
            'failed' => 'Falhou',
            'pending' => 'Pendente',
            'skipped', 'ignored' => 'Ignorado',
            default => Str::headline($status),
        };
    }

    public static function friendlySeverity(?string $severity): string
    {
        if (blank($severity)) {
            return '—';
        }

        return match ($severity) {
            'info', 'information', 'informativo' => 'Informativa',
            'warning', 'atencao' => 'Atenção',
            'danger', 'critical', 'critico', 'high' => 'Crítica',
            'low', 'baixa' => 'Baixa',
            default => Str::headline($severity),
        };
    }

    /**
     * A auditoria de lembretes expõe destinatário, motivo e criticidade de cada
     * envio. Sem policy registrada o Filament liberava o Resource por padrão, e
     * esconder o item de navegação não fecha a URL direta.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(AccessPermission::ReminderLogsView->value) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
