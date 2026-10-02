<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\DTOs\SalesBoards\SalesBoardGenerationResult;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardGenerationOutcome;
use App\Enums\SalesBoardSource;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\Construction;
use App\Services\SalesBoards\SalesBoardGenerationService;
use App\Support\BusinessTime;
use App\Support\SalesBoards\CompetenceCalendar;
use App\Support\SalesBoards\ReferenceMonthInput;
use App\Support\SalesBoards\SalesBoardFrozenWarnings;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * "Congelar competência": cria o ciclo e a versão 1 de um empreendimento.
 *
 * Uma ação de domínio, não um formulário de cadastro. Os únicos dados que o
 * operador informa são *qual* competência de *qual* empreendimento -- todo o
 * resto é apurado. Um formulário com os campos crus da tabela permitiria digitar
 * à mão uma posição que ninguém calculou, que é exatamente o problema que o
 * ciclo existe para resolver.
 *
 * A tela só oferece o que a geração aceita: empreendimentos de Emissão com a
 * automação ativa e competências já encerradas, a partir da ativação. Quem decide
 * continua sendo o {@see SalesBoardGenerationService} -- a tela só evita levar o
 * operador até uma recusa.
 *
 * Congelar é ato humano e continua disponível com o interruptor global
 * desligado e para Emissão liquidada -- é por aqui que a Gestão fecha a última
 * competência de uma operação encerrada. A opção do empreendimento diz quando a
 * Emissão está liquidada, para ninguém confundir a parada da automação com o
 * fim do caminho manual.
 *
 * A competência chega do DatePicker como `aaaa-mm-dd hh:mm:ss`, e por isso é
 * lida por {@see ReferenceMonthInput::fromDateState()}, e não pelo leitor
 * estrito da linha de comando.
 */
class GenerateSalesBoardCycleAction
{
    public static function make(string $name = 'generateCycle'): Action
    {
        /**
         * Respondido uma vez por montagem da ação, e não por processo: a ação é
         * montada de novo a cada requisição do Livewire, então a resposta vale
         * pela requisição, e `disabled()` e `tooltip()` dividem uma consulta só
         * em vez de pagar uma cada um a cada renderização do cabeçalho.
         */
        $hasCoveredConstructions = null;
        $isUnavailable = function () use (&$hasCoveredConstructions): bool {
            $hasCoveredConstructions ??= self::coveredConstructions()->exists();

            return ! $hasCoveredConstructions;
        };

        return Action::make($name)
            ->label('Congelar competência')
            ->icon('heroicon-o-camera')
            ->color('primary')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Congelar a posição de uma competência')
            ->modalDescription('A posição é apurada a partir de contratos, parcelas, tabelas de preço, políticas e permutas. Nada é digitado, e nada é gravado se faltar dado para explicar algum número.')
            ->modalSubmitActionLabel('Congelar')
            ->visible(fn (): bool => SalesBoardCycleResource::canGenerate())
            ->disabled(fn (): bool => $isUnavailable())
            ->tooltip(fn (): ?string => $isUnavailable()
                ? 'Nenhuma Emissão está com a automação do Quadro ativa. O ciclo mensal só existe para competências cobertas pela automação; nas Emissões legadas a posição é registrada em Quadro de Vendas.'
                : null)
            ->schema([
                Select::make('construction_id')
                    ->label('Empreendimento')
                    ->helperText('Só aparecem empreendimentos de Emissão com a automação do Quadro ativa.')
                    ->options(fn (): array => self::coveredConstructions()
                        ->with('emission')
                        ->orderBy('development_name')
                        ->get()
                        ->mapWithKeys(fn (Construction $construction): array => [
                            $construction->getKey() => sprintf(
                                '%s — %s (automação desde %s)%s',
                                (string) $construction->development_name,
                                (string) $construction->emission?->name,
                                (string) $construction->emission?->automationStartsAt()?->format('m/Y'),
                                ($construction->emission?->isLiquidated() ?? false) ? ' · Emissão liquidada' : '',
                            ),
                        ])
                        ->all())
                    ->searchable()
                    ->live()
                    ->required(),

                DatePicker::make('reference_month')
                    ->label('Competência')
                    ->helperText('A posição é sempre a do último dia do mês informado. Só competências encerradas no calendário de negócio, a partir da ativação da automação da Emissão.')
                    ->displayFormat('m/Y')
                    ->native(false)
                    ->required()
                    ->maxDate(fn (): string => CompetenceCalendar::lastClosedMonth()->endOfMonth()->toDateString())
                    ->minDate(fn (Get $get): ?string => self::automationStartFor($get('construction_id')))
                    ->validationMessages([
                        'before_or_equal' => sprintf(
                            'A competência ainda não terminou. A mais recente encerrada é %s.',
                            CompetenceCalendar::lastClosedMonth()->format('m/Y'),
                        ),
                        'after_or_equal' => 'A competência é anterior à ativação da automação desta Emissão; ela continua no registro manual.',
                    ])
                    ->default(fn (): CarbonImmutable => CompetenceCalendar::lastClosedMonth()),
            ])
            ->action(function (array $data): void {
                $construction = Construction::query()->with('emission')->find($data['construction_id']);
                $referenceMonth = ReferenceMonthInput::fromDateState($data['reference_month'] ?? null);

                if (($construction === null) || ($referenceMonth === null)) {
                    Notification::make()
                        ->title('Não foi possível congelar')
                        ->body('Informe um empreendimento e uma competência válidos.')
                        ->danger()
                        ->send();

                    return;
                }

                $result = app(SalesBoardGenerationService::class)->generateForConstruction(
                    construction: $construction,
                    referenceMonth: $referenceMonth,
                    actor: auth()->user(),
                );

                $notification = Notification::make()
                    ->title($result->outcome->label())
                    ->body(match ($result->outcome) {
                        SalesBoardGenerationOutcome::Generated => self::generatedBody($result),
                        SalesBoardGenerationOutcome::AlreadyExists => self::alreadyExistsBody($result),
                        SalesBoardGenerationOutcome::Blocked => self::blockedBody($result),
                    });

                match ($result->outcome) {
                    SalesBoardGenerationOutcome::Generated => $notification->success(),
                    SalesBoardGenerationOutcome::AlreadyExists => $notification->info(),
                    SalesBoardGenerationOutcome::Blocked => $notification->danger(),
                };

                $notification->send();
            });
    }

    /**
     * A competência já tem ciclo. Se ele foi cancelado, o caminho de volta não é
     * o recálculo -- recusado em ciclo cancelado --, e sim a reabertura pela
     * Gestão.
     */
    private static function alreadyExistsBody(SalesBoardGenerationResult $result): string
    {
        $cycle = $result->cycle;

        if ($cycle?->status !== SalesBoardCycleStatus::Cancelled) {
            return 'Esta competência já foi congelada. Refazer a posição é recálculo, que exige motivo.';
        }

        return sprintf(
            'A competência %s foi cancelada pela Gestão em %s. Para retomá-la, abra o ciclo e use “Reabrir competência”.',
            $result->referenceMonth->format('m/Y'),
            $cycle->cancelled_at === null ? '—' : BusinessTime::at($cycle->cancelled_at)->format('d/m/Y'),
        );
    }

    /**
     * Empreendimentos cuja Emissão tem a automação do Quadro ativa.
     *
     * É o recorte que a tela oferece; a cobertura de cada competência -- a
     * partir da ativação -- continua sendo conferida pela geração.
     *
     * @return Builder<Construction>
     */
    private static function coveredConstructions(): Builder
    {
        return Construction::query()->whereHas('emission', fn (Builder $query): Builder => $query
            ->where('sales_board_source', SalesBoardSource::Automated)
            ->whereNotNull('sales_board_automation_start_reference_month'));
    }

    /**
     * O primeiro dia da competência inicial da automação do empreendimento
     * escolhido, ou `null` enquanto nenhum foi escolhido.
     */
    private static function automationStartFor(mixed $constructionId): ?string
    {
        if (blank($constructionId)) {
            return null;
        }

        return Construction::query()
            ->with('emission')
            ->find($constructionId)
            ?->emission
            ?->automationStartsAt()
            ?->toDateString();
    }

    /**
     * A competência congelada e o próximo passo. Os avisos que a apuração
     * registrou nesta versão vêm logo abaixo, com rótulo, código, contagem e
     * onde se confere -- eles não impedem o congelamento, mas quem envia à
     * construtora precisa vê-los antes. Sem aviso, o corpo é só o texto.
     */
    private static function generatedBody(SalesBoardGenerationResult $result): string|HtmlString
    {
        $text = sprintf(
            '%s · %s: versão %s congelada. Próximo passo: enviar a posição para a validação da construtora.',
            (string) $result->constructionName,
            $result->referenceMonth->format('m/Y'),
            (string) ($result->baseline?->versionLabel() ?? 'V1'),
        );

        $warnings = SalesBoardFrozenWarnings::countsByCode($result->baseline?->frozenWarnings());

        if ($warnings === []) {
            return $text;
        }

        return new HtmlString(e($text)
            .'<br><br>A apuração registrou avisos que não impedem o congelamento; confira-os na competência antes de enviar à construtora:<br>'
            .SalesBoardIssuePresenter::toHtml($warnings)->toHtml());
    }

    /**
     * A recusa por fonte incompleta lista cada bloqueio com o que ele significa
     * e onde se corrige, sem esconder o código. As demais recusas já chegam em
     * linguagem de tela.
     */
    private static function blockedBody(SalesBoardGenerationResult $result): string|HtmlString
    {
        $counts = $result->readiness?->blockingIssueCounts() ?? [];

        if ($counts === []) {
            return (string) $result->blockedReason;
        }

        return new HtmlString(sprintf(
            '%s · %s: a fonte da competência está incompleta e nada foi congelado.<br>%s',
            e((string) $result->constructionName),
            e($result->referenceMonth->format('m/Y')),
            SalesBoardIssuePresenter::toHtml($counts)->toHtml(),
        ));
    }
}
