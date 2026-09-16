<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\DTOs\SalesBoards\SalesBoardGenerationResult;
use App\Enums\SalesBoardGenerationOutcome;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\Construction;
use App\Services\SalesBoards\SalesBoardGenerationService;
use App\Support\SalesBoards\ReferenceMonthInput;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;

/**
 * "Congelar competência": cria o ciclo e a versão 1 de um empreendimento.
 *
 * Uma ação de domínio, não um formulário de cadastro. Os únicos dados que o
 * operador informa são *qual* competência de *qual* empreendimento -- todo o
 * resto é apurado. Um formulário com os campos crus da tabela permitiria digitar
 * à mão uma posição que ninguém calculou, que é exatamente o problema que o
 * ciclo existe para resolver.
 */
class GenerateSalesBoardCycleAction
{
    public static function make(string $name = 'generateCycle'): Action
    {
        return Action::make($name)
            ->label('Congelar competência')
            ->icon('heroicon-o-camera')
            ->color('primary')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Congelar a posição de uma competência')
            ->modalDescription('A posição é apurada a partir de contratos, parcelas, tabelas de preço, políticas e permutas. Nada é digitado, e nada é gravado se faltar dado para explicar algum número.')
            ->modalSubmitActionLabel('Congelar')
            ->visible(fn (): bool => SalesBoardCycleResource::canGenerate())
            ->schema([
                Select::make('construction_id')
                    ->label('Empreendimento')
                    ->options(fn (): array => Construction::query()
                        ->with('emission')
                        ->orderBy('development_name')
                        ->get()
                        ->mapWithKeys(fn (Construction $construction): array => [
                            $construction->getKey() => sprintf(
                                '%s — %s',
                                (string) $construction->development_name,
                                (string) $construction->emission?->name,
                            ),
                        ])
                        ->all())
                    ->searchable()
                    ->required(),

                DatePicker::make('reference_month')
                    ->label('Competência')
                    ->helperText('A posição é sempre a do último dia do mês informado.')
                    ->displayFormat('m/Y')
                    ->native(false)
                    ->required()
                    ->default(fn (): string => CarbonImmutable::now()->subMonth()->startOfMonth()->toDateString()),
            ])
            ->action(function (array $data): void {
                $construction = Construction::query()->with('emission')->find($data['construction_id']);
                $referenceMonth = ReferenceMonthInput::parse($data['reference_month']);

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
                        SalesBoardGenerationOutcome::Generated => sprintf(
                            '%s · %s: versão %s congelada. Próximo passo: enviar a posição para a validação da construtora.',
                            (string) $result->constructionName,
                            $result->referenceMonth->format('m/Y'),
                            (string) ($result->baseline?->versionLabel() ?? 'V1'),
                        ),
                        SalesBoardGenerationOutcome::AlreadyExists => 'Esta competência já foi congelada. Refazer a posição é recálculo, que exige motivo.',
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
