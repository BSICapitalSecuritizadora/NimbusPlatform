<?php

namespace App\Filament\Resources\FundAllocations\Schemas;

use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\FundAllocationPeriod;
use App\Rules\AllocationCompetence;
use App\Rules\BankApplicationBelongsToAccount;
use App\Services\Funds\FundAllocationService;
use App\Support\Funds\FundAllocationMoney;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class FundAllocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Aplicação e competência')
                ->description('Escolha a conta, a aplicação física e a competência de referência. O período existente é reaproveitado — nunca duplicado.')
                ->columns(3)
                ->schema([
                    Select::make('bank_account_id')
                        ->label('Conta bancária')
                        ->options(fn (): array => BankAccount::query()
                            ->with('bank')
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(fn (BankAccount $account): array => [
                                $account->getKey() => $account->optionLabel(),
                            ])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($set): void {
                            $set('bank_application_id', null);
                        })
                        ->validationMessages([
                            'required' => 'Selecione a conta bancária.',
                        ]),

                    Select::make('bank_application_id')
                        ->label('Aplicação bancária')
                        ->options(fn (Get $get): array => static::applicationOptions($get('bank_account_id')))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get): bool => blank($get('bank_account_id')))
                        ->helperText('Somente aplicações da conta selecionada.')
                        ->rule(new BankApplicationBelongsToAccount)
                        ->validationMessages([
                            'required' => 'Selecione a aplicação bancária.',
                        ]),

                    TextInput::make('competence')
                        ->label('Competência')
                        ->placeholder('MM/AAAA')
                        ->mask('99/9999')
                        ->required()
                        ->live()
                        ->rule(new AllocationCompetence)
                        ->validationMessages([
                            'required' => 'Informe a competência.',
                        ]),
                ]),

            Section::make('Contexto da aplicação')
                ->description('Fundos vinculados, operações atendidas e situação atual da competência.')
                ->schema([
                    Placeholder::make('allocation_context')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => static::contextHtml($get)),
                ]),

            Section::make('Saldo de referência manual')
                ->description('Registrado uma vez por versão; aprovações já vinculadas mantêm a versão original.')
                ->visible(fn (Get $get): bool => auth()->user()?->can(FundAllocationService::PERMISSION_MANAGE_REFERENCES)
                    && static::currentSnapshot($get) === null
                    && static::selectedApplication($get) !== null
                    && FundAllocationDraftLines::parseCompetenceInput($get('competence')) !== null)
                ->columns(2)
                ->schema([
                    FundAllocationDraftLines::moneyInput('reference_amount', 'Valor de referência')
                        ->required()
                        ->helperText('Saldo financeiro de referência da aplicação nesta competência.'),

                    TextInput::make('reference_captured_at')
                        ->label('Data de referência')
                        ->type('date')
                        ->default(fn (): string => now()->toDateString())
                        ->required()
                        ->validationMessages([
                            'required' => 'Informe a data de referência.',
                        ]),

                    Textarea::make('reference_note')
                        ->label('Observação')
                        ->columnSpanFull()
                        ->rows(2)
                        ->maxLength(2000)
                        ->placeholder('Ex.: extrato de fechamento do mês, conferência manual…'),
                ]),

            Section::make('Distribuição')
                ->description('Informe quanto de cada fundo compõe esta competência. Totais e saldos não alocados são calculados pelo servidor.')
                ->schema([
                    FundAllocationDraftLines::linesRepeater(),

                    Placeholder::make('lines_total_hint')
                        ->label('Total proposto')
                        ->content(fn (Get $get): string => static::totalHint($get)),

                    Textarea::make('correction_reason')
                        ->label('Motivo da correção')
                        ->required()
                        ->rows(2)
                        ->maxLength(2000)
                        ->helperText('Esta competência já possui alocação aprovada: a nova proposta tramita como correção governada.')
                        ->visible(fn (Get $get): bool => static::existingPeriod($get)?->hasEffectiveRevision() ?? false)
                        ->validationMessages([
                            'required' => 'Correções exigem um motivo.',
                        ]),
                ]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function applicationOptions(mixed $accountId): array
    {
        if (blank($accountId)) {
            return [];
        }

        return BankApplication::query()
            ->where('bank_account_id', $accountId)
            ->orderBy('codigo_produto')
            ->get()
            ->mapWithKeys(fn (BankApplication $application): array => [
                $application->getKey() => $application->optionLabel(),
            ])
            ->all();
    }

    public static function selectedApplication(Get $get): ?BankApplication
    {
        $applicationId = $get('bank_application_id');

        if (blank($applicationId)) {
            return null;
        }

        return BankApplication::query()->find($applicationId);
    }

    public static function currentSnapshot(Get $get): ?BankApplicationBalanceSnapshot
    {
        $application = static::selectedApplication($get);
        $competence = FundAllocationDraftLines::parseCompetenceInput($get('competence'));

        if (! $application instanceof BankApplication || $competence === null) {
            return null;
        }

        return BankApplicationBalanceSnapshot::query()
            ->where('bank_application_id', $application->getKey())
            ->whereDate('competence', $competence)
            ->orderByDesc('version')
            ->first();
    }

    public static function existingPeriod(Get $get): ?FundAllocationPeriod
    {
        $application = static::selectedApplication($get);
        $competence = FundAllocationDraftLines::parseCompetenceInput($get('competence'));

        if (! $application instanceof BankApplication || $competence === null) {
            return null;
        }

        return FundAllocationPeriod::query()
            ->where('bank_application_id', $application->getKey())
            ->whereDate('competence', $competence)
            ->with(['openRevision', 'effectiveRevision'])
            ->first();
    }

    private static function contextHtml(Get $get): HtmlString
    {
        $application = static::selectedApplication($get);

        if (! $application instanceof BankApplication) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Selecione a aplicação para ver os fundos vinculados e a situação da competência.</p>');
        }

        $lines = [];

        if ($application->isShared()) {
            $lines[] = '<p><span class="fi-badge fi-color-warning">Compartilhado</span></p>';
            $lines[] = '<p class="text-sm text-gray-500 dark:text-gray-400">Esta aplicação física atende a fundos de mais de uma operação. A alocação distribui o saldo de referência entre todos eles.</p>';
        }

        $funds = $application->funds()->with(['emission', 'fundName'])->orderBy('id')->get();

        if ($funds->isEmpty()) {
            $lines[] = '<p class="text-sm text-gray-500 dark:text-gray-400">Nenhum fundo vinculado a esta aplicação. Vincule fundos pelo cadastro do fundo antes de alocar.</p>';
        } else {
            $showEmissions = auth()->user()?->can('emissions.view') ?? false;
            $items = $funds->map(function ($fund) use ($showEmissions): string {
                $name = e($fund->fundName?->name ?? "Fundo #{$fund->getKey()}");
                $suffix = $showEmissions ? ' — '.e($fund->emission?->name ?? '—') : '';

                return "<li>{$name} · #{$fund->getKey()}{$suffix}</li>";
            })->implode('');

            $lines[] = "<ul class=\"list-disc ps-5 text-sm\">{$items}</ul>";
        }

        $competence = FundAllocationDraftLines::parseCompetenceInput($get('competence'));

        if ($competence !== null) {
            $snapshot = static::currentSnapshot($get);

            if ($snapshot instanceof BankApplicationBalanceSnapshot) {
                $amount = FundAllocationDraftLines::formatCents(
                    FundAllocationMoney::toCents((string) $snapshot->amount)
                );
                $lines[] = "<p class=\"text-sm\">Saldo de referência manual vigente: <strong class=\"font-mono\">{$amount}</strong> (versão {$snapshot->version}).</p>";
            } elseif (auth()->user()?->can(FundAllocationService::PERMISSION_MANAGE_REFERENCES)) {
                $lines[] = '<p class="text-sm text-gray-500 dark:text-gray-400">Sem saldo de referência para esta competência — informe-o abaixo para prosseguir.</p>';
            } else {
                $lines[] = '<p class="text-sm text-warning-600 dark:text-warning-400">Sem saldo de referência para esta competência. Solicite o registro a um usuário autorizado antes de criar o rascunho.</p>';
            }

            $period = static::existingPeriod($get);

            if ($period instanceof FundAllocationPeriod) {
                if ($period->hasOpenRevision()) {
                    $lines[] = '<p class="text-sm text-warning-600 dark:text-warning-400">Esta competência já possui uma revisão em andamento — conclua ou descarte-a na tela do período em vez de criar outra.</p>';
                } elseif ($period->hasEffectiveRevision()) {
                    $lines[] = '<p class="text-sm">Esta competência já possui alocação aprovada — a nova proposta tramita como correção governada.</p>';
                }
            }
        }

        return new HtmlString(implode('', $lines));
    }

    private static function totalHint(Get $get): string
    {
        $lines = $get('lines') ?? [];

        if (! is_array($lines) || $lines === []) {
            return 'Nenhuma linha — todo o saldo de referência permanece não alocado.';
        }

        $total = 0;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $normalized = FundAllocationDraftLines::normalizeMoneyInput($line['amount'] ?? null);

            if ($normalized === null) {
                continue;
            }

            try {
                $total += FundAllocationMoney::toCents($normalized);
            } catch (\InvalidArgumentException) {
                return 'Verifique os valores informados.';
            }
        }

        return 'Total proposto: '.FundAllocationDraftLines::formatCents($total).' (o servidor recalcula e valida na gravação).';
    }
}
