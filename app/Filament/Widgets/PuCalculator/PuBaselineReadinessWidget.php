<?php

declare(strict_types=1);

namespace App\Filament\Widgets\PuCalculator;

use App\Domain\PuCalculator\Services\CdiSourceDossierGovernanceService;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Enums\AccessPermission;
use App\Filament\Resources\BusinessHolidays\BusinessHolidayResource;
use App\Models\Emission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Throwable;

class PuBaselineReadinessWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.pu-calculator.pu-baseline-readiness-widget';

    protected int|string|array $columnSpan = 'full';

    public Emission $record;

    public function approveDiDossierAction(): Action
    {
        return Action::make('approveDiDossier')
            ->label('Aprovar fonte DI')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(function (): bool {
                $dossier = app(CdiSourceDossierGovernanceService::class)->latest();

                return ($dossier['technical_homologation_satisfied'] ?? false)
                    && ! ($dossier['approved'] ?? false)
                    && (auth()->user()?->can(AccessPermission::PuCalendarHomologationReview->value) ?? false);
            })
            ->requiresConfirmation()
            ->modalHeading('Aprovar operacionalmente a fonte BCB SGS 4389')
            ->modalDescription('Confirme somente após revisar período, transformação, divergências, fontes, payloads e checksums. Esta ação não importa taxas nem cria curva.')
            ->schema([
                Textarea::make('review_notes')
                    ->label('Conclusão da revisão')
                    ->helperText('Registre o fundamento da decisão administrativa.')
                    ->required()
                    ->rows(4),
            ])
            ->action(function (array $data): void {
                /** @var User $reviewer */
                $reviewer = auth()->user();

                try {
                    app(CdiSourceDossierGovernanceService::class)->approveLatest(
                        $reviewer,
                        (string) $data['review_notes'],
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Não foi possível aprovar o dossiê.')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Fonte DI aprovada para uso operacional.')
                    ->body('Nenhuma Taxa DI foi importada por esta ação.')
                    ->success()
                    ->send();
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'report' => app(PuBaselineReadinessService::class)->evaluate($this->record),
            'calendarReviewUrl' => BusinessHolidayResource::getUrl('index'),
        ];
    }
}
