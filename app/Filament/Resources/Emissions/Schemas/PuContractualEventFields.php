<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Services\PuFinancialEffectSupport as Effect;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuObligation;
use App\Support\PuCalculator\PuDisplay;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Formulários guiados dos eventos contratuais com efeito financeiro (Fase 8).
 *
 * Cada tipo pede só os termos que a engine sabe aplicar e monta o
 * `financial_effect` que {@see Effect} exige -- ninguém digita JSON. Tipo sem regra
 * de cálculo não é oferecido: cadastrá-lo bloquearia a curva. Antes de gravar, a
 * tela confere o evento com a mesma regra da geração e recusa o que bloquearia; o
 * modelo continua aplicando as regras de domínio em qualquer caminho.
 */
final class PuContractualEventFields
{
    /** Tipos que o formulário cria, na ordem em que aparecem. */
    public const CREATABLE = [
        PuEventType::InterestPayment,
        PuEventType::Amortization,
        PuEventType::ExtraordinaryAmortization,
        PuEventType::EarlyMaturity,
        PuEventType::SpreadAmendment,
        PuEventType::Waiver,
    ];

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            PuEventType::InterestPayment->value => 'Pagamento de juros (cronograma)',
            PuEventType::Amortization->value => 'Amortização ordinária (cronograma)',
            PuEventType::ExtraordinaryAmortization->value => 'Amortização extraordinária — calculada (só CDI, termos explícitos)',
            PuEventType::EarlyMaturity->value => 'Vencimento antecipado — calculado (só CDI, regra restrita)',
            PuEventType::SpreadAmendment->value => 'Alteração futura de spread — exige nova versão homologada (só CDI)',
            PuEventType::Waiver->value => 'Waiver — só registro, sem efeito no PU',
        ];
    }

    /**
     * Campos dos tipos com efeito financeiro, visíveis conforme o tipo escolhido.
     *
     * @param  Closure(): Emission  $emission
     * @return list<Component|Field>
     */
    public static function components(Closure $emission): array
    {
        $is = fn (PuEventType $type): Closure => fn (Get $get): bool => $get('event_type') === $type->value;

        return [
            Placeholder::make('effect_support')
                ->label('Suporte financeiro deste tipo')
                ->columnSpanFull()
                ->content(fn (Get $get): string => self::supportNote(PuEventType::tryFrom((string) $get('event_type'))))
                ->visible(fn (Get $get): bool => in_array($get('event_type'), [
                    PuEventType::ExtraordinaryAmortization->value,
                    PuEventType::EarlyMaturity->value,
                    PuEventType::SpreadAmendment->value,
                    PuEventType::Waiver->value,
                ], true)),

            // Amortização extraordinária.
            Fieldset::make('Termos da amortização extraordinária')
                ->columnSpanFull()
                ->visible($is(PuEventType::ExtraordinaryAmortization))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Radio::make('xa_mode')
                        ->label('Como o contrato fixa o valor')
                        ->options([
                            PuAmortizationType::Percentage->value => 'Percentual da base',
                            PuAmortizationType::UnitValue->value => 'Valor por título (R$)',
                        ])
                        ->default(PuAmortizationType::Percentage->value)
                        ->required($is(PuEventType::ExtraordinaryAmortization))
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('xa_percent')
                        ->label('Percentual da base (%)')
                        ->helperText('Ex.: 10 para 10%. Gravado como fração (0,10) — a mesma unidade que a engine aplica.')
                        ->numeric()
                        ->minValue(0.00000001)
                        ->maxValue(100)
                        ->suffix('%')
                        ->rule('regex:/^\d+(\.\d{1,14})?$/')
                        ->validationMessages(['regex' => 'Use até 14 casas decimais: o valor não é arredondado.'])
                        ->visible(fn (Get $get): bool => $get('xa_mode') === PuAmortizationType::Percentage->value)
                        ->required(fn (Get $get): bool => $get('event_type') === PuEventType::ExtraordinaryAmortization->value && $get('xa_mode') === PuAmortizationType::Percentage->value),
                    TextInput::make('xa_unit_value')
                        ->label('Valor por título (R$)')
                        ->numeric()
                        ->minValue(0.00000001)
                        ->prefix('R$')
                        ->rule('regex:/^\d+(\.\d{1,16})?$/')
                        ->validationMessages(['regex' => 'Use até 16 casas decimais: o valor não é arredondado.'])
                        ->visible(fn (Get $get): bool => $get('xa_mode') === PuAmortizationType::UnitValue->value)
                        ->required(fn (Get $get): bool => $get('event_type') === PuEventType::ExtraordinaryAmortization->value && $get('xa_mode') === PuAmortizationType::UnitValue->value),
                    Radio::make('xa_accrued_interest')
                        ->label('Juros do período até a data efetiva')
                        ->options([
                            Effect::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT => 'Pagos pelo evento de pagamento de juros da mesma data',
                            Effect::ACCRUED_INTEREST_CAPITALIZED => 'Incorporados ao principal (sem pagamento de juros na data)',
                        ])
                        ->descriptions([
                            Effect::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT => 'Exige um pagamento de juros ativo na mesma data efetiva.',
                            Effect::ACCRUED_INTEREST_CAPITALIZED => 'Recusado se houver pagamento de juros ativo na mesma data.',
                        ])
                        ->required($is(PuEventType::ExtraordinaryAmortization))
                        ->columnSpanFull(),
                    Checkbox::make('xa_reduction_on_effective_date')
                        ->label('O principal é reduzido na data efetiva do evento (única regra suportada; redução na data da liquidação não é).')
                        ->accepted($is(PuEventType::ExtraordinaryAmortization))
                        ->validationMessages(['accepted' => 'Só a redução na data efetiva é suportada.'])
                        ->columnSpanFull(),
                    Toggle::make('xa_has_cap')
                        ->label('O Termo fixa um teto para a amortização extraordinária')
                        ->helperText('Não existe teto universal: preencha só o que o Termo desta emissão fixa. Acima do teto a curva é recusada, nunca truncada.')
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('xa_cap_percent')
                        ->label('Teto contratual (% da mesma base)')
                        ->helperText('Ex.: 98 para 98%. Gravado como fração (maximum_principal_fraction = 0,98).')
                        ->numeric()
                        ->minValue(0.00000001)
                        ->maxValue(100)
                        ->suffix('%')
                        ->rule('regex:/^\d+(\.\d{1,14})?$/')
                        ->validationMessages(['regex' => 'Use até 14 casas decimais: o valor não é arredondado.'])
                        ->visible(fn (Get $get): bool => (bool) $get('xa_has_cap'))
                        ->required(fn (Get $get): bool => $get('event_type') === PuEventType::ExtraordinaryAmortization->value && (bool) $get('xa_has_cap')),
                    Placeholder::make('xa_unsupported')
                        ->label('Não coberto por este formulário')
                        ->content('Prêmio sobre a amortização extraordinária, base de cálculo específica (ex.: VNU de outra data) e juros só sobre a parcela amortizada não têm regra no sistema. Se o Termo os prevê, não cadastre: a emissão não está financeiramente pronta.')
                        ->columnSpanFull(),
                ]),

            // Vencimento antecipado.
            Fieldset::make('Termos do vencimento antecipado')
                ->columnSpanFull()
                ->visible($is(PuEventType::EarlyMaturity))
                ->columns(1)
                ->schema([
                    Checkbox::make('em_outstanding_balance')
                        ->label('O principal acelerado é o saldo devedor na data efetiva.')
                        ->accepted($is(PuEventType::EarlyMaturity))
                        ->validationMessages(['accepted' => 'Só o principal acelerado igual ao saldo é suportado.']),
                    Checkbox::make('em_pro_rata_interest')
                        ->label('Os juros ordinários correm pro rata até a data efetiva.')
                        ->accepted($is(PuEventType::EarlyMaturity))
                        ->validationMessages(['accepted' => 'Só os juros ordinários pro rata são suportados.']),
                    Checkbox::make('em_no_additional_charges')
                        ->label('O contrato NÃO prevê prêmio, multa nem juros moratórios no vencimento antecipado.')
                        ->helperText('Se prevê, o vencimento antecipado não é calculável aqui: não cadastre e trate a emissão como não pronta.')
                        ->accepted($is(PuEventType::EarlyMaturity))
                        ->validationMessages(['accepted' => 'Encargos do vencimento antecipado não têm regra de cálculo: cadastro bloqueado.']),
                    Placeholder::make('em_preview')
                        ->label('Consequência prevista')
                        ->content(fn (Get $get): string => self::earlyMaturityPreview($emission(), $get('effective_date'))),
                ]),

            // Alteração futura de spread.
            Fieldset::make('Repactuação do spread')
                ->columnSpanFull()
                ->visible($is(PuEventType::SpreadAmendment))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Select::make('sa_effective_date')
                        ->label('Vigência (início de um período de capitalização)')
                        ->options(fn (): array => self::periodStartOptions($emission()))
                        ->helperText('Só datas em que um período começa (pagamento de juros, amortização com valor ou vencimento antecipado). No meio do período não há regra para dividir os juros.')
                        ->required($is(PuEventType::SpreadAmendment)),
                    TextInput::make('sa_spread_rate')
                        ->label('Novo spread (% a.a.)')
                        ->numeric()
                        ->minValue(-99.99999999)
                        ->suffix('% a.a.')
                        ->rule('regex:/^-?\d+(\.\d{1,8})?$/')
                        ->validationMessages(['regex' => 'Use até 8 casas decimais (a escala do spread): o valor não é arredondado.'])
                        ->required($is(PuEventType::SpreadAmendment)),
                    Placeholder::make('sa_impact')
                        ->label('Impacto')
                        ->content('Não altera a curva oficial vigente: ela avança até a véspera da vigência e para, com "nova versão necessária". Para valer, gere uma nova versão, valide e homologue (maker/checker).')
                        ->columnSpanFull(),
                ]),

            // Waiver.
            Fieldset::make('Waiver')
                ->columnSpanFull()
                ->visible($is(PuEventType::Waiver))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    DatePicker::make('effective_until')
                        ->label('Vigente até (opcional)'),
                    Checkbox::make('wv_no_pu_effect')
                        ->label('Confirmo que o waiver não altera juros, amortização, prazo ou qualquer valor do PU.')
                        ->helperText('Suspensão de juros, diferimento ou taxa de waiver não têm regra de cálculo: com efeito financeiro, o waiver bloquearia a curva.')
                        ->accepted($is(PuEventType::Waiver))
                        ->validationMessages(['accepted' => 'Só o waiver sem efeito no PU é suportado.'])
                        ->columnSpanFull(),
                ]),

            TextInput::make('document_reference')
                ->label('Documento e cláusula')
                ->placeholder('Ex.: Termo de Securitização, cláusula 5.3, p. 42')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => in_array($get('event_type'), [
                    PuEventType::ExtraordinaryAmortization->value,
                    PuEventType::EarlyMaturity->value,
                    PuEventType::SpreadAmendment->value,
                    PuEventType::Waiver->value,
                ], true))
                ->required(fn (Get $get): bool => in_array($get('event_type'), [
                    PuEventType::ExtraordinaryAmortization->value,
                    PuEventType::EarlyMaturity->value,
                    PuEventType::SpreadAmendment->value,
                ], true)),
        ];
    }

    /**
     * Os campos do formulário viram os atributos do evento (e o `financial_effect`
     * canônico); os auxiliares não chegam ao modelo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function payload(array $data): array
    {
        $type = PuEventType::tryFrom((string) ($data['event_type'] ?? ''));
        $base = array_intersect_key($data, array_flip([
            'event_type', 'original_date', 'effective_date', 'effective_until', 'effective_date_reason',
            'effective_date_justification', 'effective_date_evidence_reference', 'effective_date_evidence_excerpt',
            'amortization_type', 'amortization_value', 'sequence', 'description', 'document_reference',
        ]));

        return match ($type) {
            PuEventType::ExtraordinaryAmortization => [
                ...$base,
                'original_date' => null,
                'amortization_type' => (string) $data['xa_mode'],
                'amortization_value' => $data['xa_mode'] === PuAmortizationType::Percentage->value
                    ? self::fraction($data['xa_percent'] ?? null)
                    : self::decimal($data['xa_unit_value'] ?? null),
                'financial_effect' => array_filter([
                    Effect::KEY_PRINCIPAL_REDUCTION => Effect::PRINCIPAL_REDUCTION_ON_EFFECTIVE_DATE,
                    Effect::KEY_ACCRUED_INTEREST => (string) ($data['xa_accrued_interest'] ?? ''),
                    Effect::KEY_MAXIMUM_PRINCIPAL_FRACTION => (bool) ($data['xa_has_cap'] ?? false) ? self::fraction($data['xa_cap_percent'] ?? null) : null,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ],
            PuEventType::EarlyMaturity => [
                ...$base,
                'original_date' => null,
                'amortization_type' => PuAmortizationType::None->value,
                'amortization_value' => null,
                'financial_effect' => [
                    Effect::KEY_ACCELERATED_PRINCIPAL => Effect::ACCELERATED_PRINCIPAL_OUTSTANDING_BALANCE,
                    Effect::KEY_ACCRUED_INTEREST => Effect::ACCRUED_INTEREST_ORDINARY_PRO_RATA,
                    Effect::KEY_ADDITIONAL_CHARGES => Effect::NONE,
                ],
            ],
            PuEventType::SpreadAmendment => [
                ...$base,
                'original_date' => null,
                'effective_date' => (string) ($data['sa_effective_date'] ?? ''),
                'amortization_type' => PuAmortizationType::None->value,
                'amortization_value' => null,
                'financial_effect' => ['spread_rate' => self::decimal($data['sa_spread_rate'] ?? null, 8)],
            ],
            PuEventType::Waiver => [
                ...$base,
                'original_date' => null,
                'amortization_type' => PuAmortizationType::None->value,
                'amortization_value' => null,
                'financial_effect' => [Effect::KEY_PU_EFFECT => Effect::NONE],
            ],
            default => $base,
        };
    }

    /**
     * O que a geração da curva recusaria neste evento, com os eventos ativos da
     * emissão -- a mesma regra, consultada antes de gravar.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function issues(array $payload, Emission $emission): array
    {
        $candidate = [
            'event_type' => (string) ($payload['event_type'] ?? ''),
            'effective_date' => filled($payload['effective_date'] ?? null) ? CarbonImmutable::parse((string) $payload['effective_date'])->toDateString() : null,
            'sequence' => (int) ($payload['sequence'] ?? 1),
            'amortization_type' => $payload['amortization_type'] ?? null,
            'amortization_value' => $payload['amortization_value'] ?? null,
            'financial_effect' => $payload['financial_effect'] ?? null,
        ];
        $events = $emission->puEvents()->active()->get()
            ->map(fn (EmissionPuEvent $event): array => Effect::fromModel($event))
            ->push($candidate)
            ->values()
            ->all();

        return app(Effect::class)->issues($candidate, $events, $emission->puParameter?->resolvedCalculationMethod());
    }

    public static function supportNote(?PuEventType $type): string
    {
        return match ($type) {
            PuEventType::ExtraordinaryAmortization => 'Calculada pela curva oficial só com indexador CDI e com os termos declarados abaixo. Gera obrigação própria de amortização extraordinária. A curva oficial vigente não muda: o evento vale a partir de uma nova versão homologada.',
            PuEventType::EarlyMaturity => 'Calculado só com indexador CDI e só com principal = saldo, juros ordinários pro rata e sem encargos. Encurta o horizonte e supera as obrigações ordinárias posteriores na próxima versão homologada.',
            PuEventType::SpreadAmendment => 'Muda a regra de juros a partir do início de um período (Fase 4). Não é obrigação financeira e não muda a versão oficial já homologada.',
            PuEventType::Waiver => 'Só o waiver sem efeito no PU é aceito: fica registrado no retrato do contrato e a engine o ignora. Qualquer efeito financeiro bloquearia a curva.',
            default => '',
        };
    }

    /**
     * Datas em que um período de capitalização começa, depois do início da curva.
     *
     * @return array<string, string>
     */
    public static function periodStartOptions(Emission $emission): array
    {
        $parameter = $emission->puParameter;
        $start = $parameter?->curve_start_date?->toDateString();
        $end = $parameter?->curve_end_date?->toDateString();
        $options = [];

        foreach ($emission->puEvents()->active()->orderBy('effective_date')->get() as $event) {
            $type = PuEventType::tryFrom((string) $event->event_type);
            $resets = $type === PuEventType::InterestPayment
                || $type === PuEventType::EarlyMaturity
                || (in_array($type, [PuEventType::Amortization, PuEventType::ExtraordinaryAmortization], true)
                    && PuAmortizationType::tryFrom((string) $event->amortization_type) !== PuAmortizationType::None);
            $date = $event->effective_date?->toDateString();

            if ($resets && $date !== null && ($start === null || $date > $start) && ($end === null || $date < $end)) {
                $options[$date] = sprintf('%s — início do período após %s', PuDisplay::date($event->effective_date), mb_strtolower($type->label()));
            }
        }

        return $options;
    }

    /**
     * Prévia somente leitura do vencimento antecipado: o que deixa de valer.
     */
    public static function earlyMaturityPreview(Emission $emission, mixed $effectiveDate): string
    {
        if (blank($effectiveDate)) {
            return 'Informe a data efetiva para ver os pagamentos e as obrigações que deixariam de valer.';
        }

        $date = CarbonImmutable::parse((string) $effectiveDate)->toDateString();
        $later = $emission->puEvents()->active()
            ->whereIn('event_type', [PuEventType::InterestPayment->value, PuEventType::Amortization->value])
            ->whereDate('effective_date', '>', $date)
            ->orderBy('effective_date')
            ->pluck('effective_date');
        $obligations = EmissionPuObligation::query()
            ->where('emission_id', $emission->id)
            ->active()
            ->where('obligation_type', PuObligationType::ScheduledPayment->value)
            ->whereDate('due_date', '>', $date)
            ->count();

        return sprintf(
            'Principal acelerado = saldo devedor em %s, com juros ordinários pro rata até a data. %d pagamento(s) do cronograma posterior(es)%s deixa(m) de valer, e %d obrigação(ões) oficial(is) posterior(es) ficam superadas quando a nova versão for homologada. A versão oficial vigente não muda até lá.',
            CarbonImmutable::parse($date)->format('d/m/Y'),
            $later->count(),
            $later->isEmpty() ? '' : ' ('.$later->take(6)->map(fn ($day): string => CarbonImmutable::parse((string) $day)->format('d/m/Y'))->implode(', ').($later->count() > 6 ? '…' : '').')',
            $obligations,
        );
    }

    /**
     * Resumo do efeito financeiro declarado, para a tabela.
     */
    public static function effectSummary(EmissionPuEvent $event): ?string
    {
        $type = PuEventType::tryFrom((string) $event->event_type);
        $effect = is_array($event->financial_effect) ? $event->financial_effect : [];

        return match ($type) {
            PuEventType::ExtraordinaryAmortization => trim(sprintf(
                '%s; principal reduzido na data efetiva; juros %s%s',
                self::amortizationLabel($event),
                ($effect[Effect::KEY_ACCRUED_INTEREST] ?? null) === Effect::ACCRUED_INTEREST_CAPITALIZED ? 'incorporados ao principal' : 'pagos no evento de juros',
                isset($effect[Effect::KEY_MAXIMUM_PRINCIPAL_FRACTION]) ? '; teto '.PuDisplay::fractionAsPercent($effect[Effect::KEY_MAXIMUM_PRINCIPAL_FRACTION]) : '; sem teto declarado',
            )),
            PuEventType::EarlyMaturity => 'Principal = saldo; juros pro rata; sem encargos',
            PuEventType::SpreadAmendment => isset($effect['spread_rate']) ? 'Novo spread '.PuDisplay::decimal((string) $effect['spread_rate'], 4).'% a.a.' : null,
            PuEventType::Waiver => 'Sem efeito no PU',
            PuEventType::Amortization => self::amortizationLabel($event),
            default => null,
        };
    }

    public static function amortizationLabel(EmissionPuEvent $event): string
    {
        $value = $event->amortization_value !== null ? (string) $event->amortization_value : null;

        return match (PuAmortizationType::tryFrom((string) $event->amortization_type)) {
            PuAmortizationType::Percentage => (PuDisplay::fractionAsPercent($value) ?? '—').' da base',
            PuAmortizationType::UnitValue => (PuDisplay::unitValue($value) ?? '—').' por título',
            PuAmortizationType::Residual => 'Saldo residual',
            default => '—',
        };
    }

    private static function fraction(mixed $percent): ?string
    {
        return is_numeric($percent) ? rtrim(rtrim(bcdiv((string) $percent, '100', 16), '0'), '.') : null;
    }

    private static function decimal(mixed $value, int $scale = 16): ?string
    {
        return is_numeric($value) ? bcadd((string) $value, '0', $scale) : null;
    }
}
