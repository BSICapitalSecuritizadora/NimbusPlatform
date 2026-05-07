<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Schemas;

use App\Actions\Expenses\LookupExpenseServiceProviderCnpj;
use App\Filament\Resources\ExpenseServiceProviderTypes\Schemas\ExpenseServiceProviderTypeForm;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ExpenseServiceProviderForm
{
    protected const LOOKUP_ALREADY_RESOLVED_FLAG = '__expense_service_provider_lookup_resolved';

    /**
     * @return array<int, Select|TextInput>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('cnpj')
                ->label('CNPJ')
                ->placeholder('00.000.000/0000-00')
                ->mask('99.999.999/9999-99')
                ->formatStateUsing(fn (?string $state): string => ExpenseServiceProvider::formatCnpj($state))
                ->stripCharacters(['.', '/', '-'])
                ->required()
                ->rule('digits:14')
                ->unique(ignoreRecord: true)
                ->validationMessages([
                    'digits' => 'Informe um CNPJ válido com 14 dígitos.',
                    'unique' => 'Já existe um prestador cadastrado com este CNPJ.',
                ])
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    self::resolveServiceProviderNameFromCnpj($get, $set, $state);
                }),

            Select::make('expense_service_provider_type_id')
                ->label('Tipo')
                ->options(fn (): array => self::getServiceProviderTypeOptions())
                ->searchable()
                ->preload()
                ->required()
                ->getSearchResultsUsing(
                    fn (string $search): array => self::getServiceProviderTypeOptions($search),
                )
                ->getOptionLabelUsing(
                    fn (mixed $value): ?string => self::getServiceProviderTypeLabel($value),
                )
                ->createOptionForm(ExpenseServiceProviderTypeForm::fields())
                ->editOptionForm(ExpenseServiceProviderTypeForm::fields())
                ->createOptionUsing(
                    fn (array $data): int => (int) ExpenseServiceProviderType::query()->create($data)->getKey(),
                )
                ->fillEditOptionActionFormUsing(
                    fn (Select $component): ?array => ExpenseServiceProviderType::query()
                        ->find($component->getState())
                        ?->attributesToArray(),
                )
                ->updateOptionUsing(function (array $data, Schema $schema): void {
                    $schema->getRecord()?->update($data);
                })
                ->createOptionAction(
                    fn (Action $action): Action => $action
                        ->label('Cadastrar tipo')
                        ->modalHeading('Cadastrar tipo de prestador de serviço'),
                )
                ->editOptionAction(
                    fn (Action $action): Action => $action
                        ->label('Editar tipo')
                        ->modalHeading('Editar tipo de prestador de serviço'),
                )
                ->validationMessages([
                    'required' => 'Selecione o tipo do prestador de serviço.',
                ]),

            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do prestador')
                ->schema(static::fields())
                ->columns(2),
        ]);
    }

    protected static function resolveServiceProviderNameFromCnpj(Get $get, Set $set, ?string $state): void
    {
        $cnpj = Str::digitsOnly((string) $state);
        $resolvedCnpj = Str::digitsOnly((string) $get(self::LOOKUP_ALREADY_RESOLVED_FLAG));

        if (strlen($cnpj) !== 14) {
            if ($resolvedCnpj !== '') {
                $set(self::LOOKUP_ALREADY_RESOLVED_FLAG, null);
            }

            return;
        }

        if ($cnpj === $resolvedCnpj) {
            return;
        }

        $result = app(LookupExpenseServiceProviderCnpj::class)->handle($cnpj);

        if ($result['status'] !== 200) {
            Notification::make()
                ->title((string) ($result['payload']['error'] ?? 'Não foi possível consultar o CNPJ informado.'))
                ->danger()
                ->send();

            return;
        }

        $set('name', (string) data_get($result, 'payload.data.name', ''));
        $set(self::LOOKUP_ALREADY_RESOLVED_FLAG, $cnpj);
    }

    /**
     * @return array<int, string>
     */
    protected static function getServiceProviderTypeOptions(?string $search = null): array
    {
        return ExpenseServiceProviderType::query()
            ->when(
                filled($search),
                fn ($query): mixed => $query->where('name', 'like', '%'.trim((string) $search).'%'),
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected static function getServiceProviderTypeLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return ExpenseServiceProviderType::query()->whereKey($value)->value('name');
    }
}
