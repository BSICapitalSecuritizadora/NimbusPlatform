<?php

namespace App\Filament\Resources\SalesBoardRollouts\Pages;

use App\Filament\Resources\SalesBoardRollouts\SalesBoardRolloutResource;
use App\Models\Emission;
use App\Services\SalesBoards\SalesBoardReadinessPreviewService;
use App\Support\SalesBoards\CompetenceCalendar;
use App\Support\SalesBoards\ReferenceMonthInput;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;

/**
 * "Prévia de prontidão do Quadro": o que a automação encontraria numa
 * competência desta Emissão, calculado sob demanda e sem gravar nada.
 *
 * Somente leitura, para quem enxerga o rollout. O cálculo deriva cada
 * empreendimento da Emissão -- perto de 0,4 s por obra de 57 mil parcelas --, e
 * por isso só roda quando alguém pede ("Calcular prévia"), com limite de dez
 * prévias por minuto por pessoa.
 *
 * O resultado fica numa propriedade travada contra alteração pelo navegador, e
 * só com agregados: situação, contagens por balde e códigos de bloqueio e de
 * aviso. Nenhuma linha de unidade nem dado de comprador vai para o navegador.
 */
class PreviewSalesBoardReadiness extends Page
{
    use InteractsWithRecord;

    /**
     * Quantas prévias seguidas uma pessoa pode calcular por minuto.
     */
    public const PREVIEWS_PER_MINUTE = 10;

    protected static string $resource = SalesBoardRolloutResource::class;

    protected string $view = 'filament.resources.sales-board-rollouts.pages.preview-sales-board-readiness';

    protected static ?string $title = 'Prévia de prontidão do Quadro';

    protected static ?string $breadcrumb = 'Prévia de prontidão';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-sales-board-readiness-page',
    ];

    /**
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $preview = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(SalesBoardRolloutResource::canView($this->record), 403);
    }

    public function getSubheading(): ?string
    {
        return sprintf(
            '%s · o que a automação encontraria numa competência, empreendimento a empreendimento. Nada é gravado.',
            (string) $this->getRecord()->getAttribute('name'),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->calculatePreviewAction(),
        ];
    }

    protected function calculatePreviewAction(): Action
    {
        return Action::make('calculatePreview')
            ->label('Calcular prévia')
            ->icon('heroicon-o-calculator')
            ->color('primary')
            ->modalWidth(Width::Large)
            ->modalHeading('Calcular a prévia de prontidão')
            ->modalDescription('Cada empreendimento da Emissão é apurado na competência escolhida, como a automação faria. Nada é gravado.')
            ->modalSubmitActionLabel('Calcular')
            ->visible(fn (): bool => SalesBoardRolloutResource::canView($this->getRecord()))
            ->schema([
                DatePicker::make('reference_month')
                    ->label('Competência')
                    ->helperText('A posição é a do último dia do mês. A competência em curso pode ser consultada, mas mostra uma posição parcial.')
                    ->displayFormat('m/Y')
                    ->native(false)
                    ->required()
                    ->maxDate(fn (): string => CompetenceCalendar::currentMonth()->endOfMonth()->toDateString())
                    ->validationMessages([
                        'before_or_equal' => 'A prévia vai, no máximo, até a competência em curso.',
                    ])
                    ->default(fn (): CarbonImmutable => CompetenceCalendar::lastClosedMonth()),
            ])
            ->action(function (array $data, Action $action): void {
                /**
                 * Reconferido aqui, e não só na montagem da página: quem perdeu
                 * a permissão com a página aberta não calcula nada.
                 */
                abort_unless(SalesBoardRolloutResource::canView($this->getRecord()), 403);

                $allowed = RateLimiter::attempt(
                    'sales-board-readiness-preview:'.auth()->id(),
                    self::PREVIEWS_PER_MINUTE,
                    fn (): bool => true,
                    60,
                );

                if (! $allowed) {
                    Notification::make()
                        ->title('Muitas prévias seguidas. Aguarde um minuto.')
                        ->warning()
                        ->send();

                    $action->cancel();

                    return;
                }

                $referenceMonth = ReferenceMonthInput::fromDateState($data['reference_month'] ?? null);

                if ($referenceMonth === null) {
                    Notification::make()
                        ->title('Informe uma competência válida.')
                        ->danger()
                        ->send();

                    $action->cancel();

                    return;
                }

                /** @var Emission $emission */
                $emission = $this->getRecord();

                $this->preview = app(SalesBoardReadinessPreviewService::class)
                    ->preview($emission, $referenceMonth)
                    ->toArray();
            });
    }
}
