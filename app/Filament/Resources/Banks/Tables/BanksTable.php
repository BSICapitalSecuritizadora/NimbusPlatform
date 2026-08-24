<?php

namespace App\Filament\Resources\Banks\Tables;

use App\Filament\Resources\Banks\BankResource;
use App\Filament\Resources\Funds\FundResource;
use App\Models\Bank;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class BanksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Bank $record): ?string => BankResource::canEdit($record)
                ? BankResource::getUrl('edit', ['record' => $record])
                : null)
            ->searchable(Bank::query()->exists())
            ->searchPlaceholder('Buscar banco...')
            ->searchDebounce('400ms')
            ->defaultSort('name')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveSearch($livewire)
                ? 'Nenhum banco encontrado'
                : 'Nenhum banco cadastrado')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveSearch($livewire)
                ? 'Ajuste ou limpe a busca para visualizar os bancos cadastrados.'
                : 'Cadastre a primeira instituição bancária para utilizá-la nos dados financeiros dos fundos.')
            ->emptyStateIcon('heroicon-o-building-library')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeiro banco')
                    ->icon('heroicon-m-plus')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => ! static::hasActiveSearch($livewire)),

                Action::make('clear_table_search')
                    ->label('Limpar busca')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => static::hasActiveSearch($livewire))
                    ->action(function ($livewire): void {
                        $livewire->resetTableSearch();
                    }),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Banco')
                    ->formatStateUsing(fn ($state, Bank $record): HtmlString => static::renderBankCell($record))
                    ->html()
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->wrap(),

                TextColumn::make('funds_count')
                    ->label('Fundos vinculados')
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        0 => '—',
                        1 => '1 fundo',
                        default => "{$state} fundos",
                    })
                    ->badge(fn (int $state): bool => $state > 0)
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray')
                    ->url(fn (Bank $record): ?string => $record->funds_count > 0
                        ? FundResource::getUrl('index', ['tableFilters' => ['bank_id' => ['value' => $record->getKey()]]])
                        : null)
                    ->tooltip(fn (Bank $record): ?string => $record->funds_count > 0 ? 'Ver fundos vinculados a este banco' : null)
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-m-pencil-square'),
                    DeleteAction::make()
                        ->label('Excluir')
                        ->visible(fn (Bank $record): bool => BankResource::canDelete($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações')
                    ->dropdownWidth(Width::ExtraSmall),
            ]);
    }

    protected static function renderBankCell(Bank $record): HtmlString
    {
        $name = e($record->name);
        $logoPath = trim((string) $record->logo_path);
        $logoUrl = filled($logoPath) ? Storage::disk('public')->url($logoPath) : null;

        if ($logoUrl) {
            $avatarHtml = '<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/90 p-1 border border-slate-200 dark:border-slate-700/50 dark:bg-[#091b23] shadow-xs">
                <img src="'.e($logoUrl).'" alt="'.$name.'" class="h-full w-full object-contain" loading="lazy" />
            </div>';
        } else {
            $initials = static::extractInitials($record->name);
            $avatarHtml = '<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700 dark:bg-[#12313b] dark:text-[#fbfaf8]/80 text-xs font-bold border border-slate-200 dark:border-slate-700/50 tracking-wider shadow-xs">
                '.e($initials).'
            </div>';
        }

        return new HtmlString('
            <div class="flex items-center gap-3">
                '.$avatarHtml.'
                <span class="font-semibold text-slate-900 dark:text-[#fbfaf8] leading-tight">'.$name.'</span>
            </div>
        ');
    }

    protected static function extractInitials(?string $name): string
    {
        if (blank($name)) {
            return 'BK';
        }

        $words = preg_split('/\s+/', trim($name));
        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, min(2, mb_strlen($name))));
    }

    protected static function hasActiveSearch(mixed $livewire): bool
    {
        return filled($livewire->getTableSearch());
    }
}
