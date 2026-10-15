<?php

namespace App\Filament\Resources\FundAllocations\Schemas;

use App\Models\Fund;
use App\Rules\AllocationMoneyAmount;
use App\Support\Funds\FundAllocationMoney;
use Carbon\Carbon;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\RawJs;

/**
 * Shared building blocks for the allocation draft forms.
 *
 * Used by the creation page and by the draft/correction actions on the
 * period view, so every entry point validates money the same way: Brazilian
 * display ("1.234,56") normalized to an exact decimal string — never a
 * float — before reaching the domain service, which remains the only
 * authority over totals.
 */
class FundAllocationDraftLines
{
    /**
     * Fund options for the selected application, grouped by emission.
     *
     * Emission names render only for users holding emissions.view; everyone
     * else sees a single neutral group. Labels always carry the fund id so
     * several funds of one emission stay individually identifiable.
     *
     * @return array<string, array<int, string>>
     */
    public static function eligibleFundOptions(?int $applicationId): array
    {
        if ($applicationId === null) {
            return [];
        }

        $funds = Fund::query()
            ->where('bank_application_id', $applicationId)
            ->with(['emission', 'fundName', 'fundApplication'])
            ->orderBy('id')
            ->get();

        if ($funds->isEmpty()) {
            return [];
        }

        $showEmissions = auth()->user()?->can('emissions.view') ?? false;

        $options = [];

        foreach ($funds as $fund) {
            $group = $showEmissions
                ? ($fund->emission?->name ?? "Operação #{$fund->emission_id}")
                : 'Fundos vinculados';

            $label = $fund->fundName?->name ?? "Fundo #{$fund->getKey()}";

            if ($fund->fundApplication?->name !== null) {
                $label .= " · {$fund->fundApplication->name}";
            }

            $label .= " · #{$fund->getKey()}";

            $options[$group][$fund->getKey()] = $label;
        }

        return $options;
    }

    public static function linesRepeater(string $statePath = 'lines', ?int $fixedApplicationId = null): Repeater
    {
        return Repeater::make($statePath)
            ->label('Linhas da alocação')
            ->columns(2)
            ->defaultItems(0)
            ->addActionLabel('Adicionar fundo')
            ->reorderable(false)
            ->collapsible()
            ->schema([
                Select::make('fund_id')
                    ->label('Fundo')
                    ->options(fn (Get $get): array => static::eligibleFundOptions(
                        $fixedApplicationId ?? static::resolveApplicationId($get)
                    ))
                    ->searchable()
                    ->required()
                    ->distinct()
                    ->validationMessages([
                        'required' => 'Selecione o fundo.',
                        'distinct' => 'Cada fundo aparece uma única vez por revisão.',
                    ]),
                static::moneyInput('amount', 'Valor alocado')->required(),
            ])
            ->helperText('Um rascunho vazio é válido: todo o saldo de referência permanece não alocado até a revisão ser concluída.');
    }

    public static function moneyInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->placeholder('0,00')
            ->extraInputAttributes(['class' => 'font-mono text-right tabular-nums'])
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => static::formatMoneyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?string => static::normalizeMoneyInput($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?string => static::normalizeMoneyInput($state))
            ->rule(new AllocationMoneyAmount)
            ->validationMessages([
                'required' => "Informe {$label}.",
            ]);
    }

    /**
     * "MM/AAAA" (or "AAAA-MM") into the domain competence ("AAAA-MM-01").
     */
    public static function parseCompetenceInput(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        if (preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $trimmed, $matches)) {
            return sprintf('%s-%s-01', $matches[2], $matches[1]);
        }

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $trimmed, $matches)) {
            return sprintf('%s-%s-01', $matches[1], $matches[2]);
        }

        return null;
    }

    /**
     * Brazilian/plain money input into an exact decimal string.
     *
     * Returns null for blank input and the trimmed original when unparseable,
     * so the validation rule (and, ultimately, the domain service) reports
     * the failure instead of coercing a wrong value.
     */
    public static function normalizeMoneyInput(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return sprintf('%.2F', $value);
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        $plain = str_replace(['R$', ' '], '', $trimmed);

        if (str_contains($plain, ',')) {
            $plain = str_replace('.', '', $plain);
            $plain = str_replace(',', '.', $plain);
        }

        if (! preg_match('/^-?\d+(?:\.\d{1,6})?$/', $plain)) {
            return $trimmed;
        }

        return $plain;
    }

    public static function formatMoneyForDisplay(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $cents = FundAllocationMoney::toCents(static::normalizeMoneyInput($value) ?? '');
        } catch (\InvalidArgumentException) {
            return trim((string) $value);
        }

        return static::formatCents($cents, withSymbol: false);
    }

    public static function formatCents(int $cents, bool $withSymbol = true): string
    {
        $plain = FundAllocationMoney::fromCents($cents);
        $sign = str_starts_with($plain, '-') ? '-' : '';
        [$units, $fraction] = explode('.', ltrim($plain, '-'));
        $grouped = strrev(implode('.', str_split(strrev($units), 3)));

        return ($withSymbol ? 'R$ ' : '')."{$sign}{$grouped},{$fraction}";
    }

    /**
     * Display-only share with two decimals, computed on integer cents.
     */
    public static function percentage(int $partCents, int $wholeCents): string
    {
        if ($wholeCents <= 0) {
            return '—';
        }

        $hundredths = intdiv($partCents * 10000, $wholeCents);
        $remainder = ($partCents * 10000) % $wholeCents;

        if ($remainder * 2 >= $wholeCents) {
            $hundredths++;
        }

        return number_format($hundredths / 100, 2, ',', '.').'%';
    }

    public static function competenceLabel(?string $competenceDate): string
    {
        if ($competenceDate === null) {
            return '—';
        }

        try {
            return Carbon::parse($competenceDate)->format('m/Y');
        } catch (\Throwable) {
            return $competenceDate;
        }
    }

    private static function resolveApplicationId(Get $get): ?int
    {
        $raw = $get('bank_application_id')
            ?? $get('../../bank_application_id')
            ?? $get('../../../bank_application_id');

        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }
}
