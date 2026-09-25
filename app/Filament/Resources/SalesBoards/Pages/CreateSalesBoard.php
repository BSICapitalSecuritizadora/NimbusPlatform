<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Exceptions\SalesBoardRolloutException;
use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\SalesBoard;
use App\Services\SalesBoards\SalesBoardPositionReader;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Single entry point for recording a sales board position.
 *
 * Opened from an existing position it behaves as "new update": the form starts
 * as a copy of that position so the user only touches what changed. Saving
 * never edits the previous record -- either a new board is created for a new
 * competence, or the existing competence receives a new version through the
 * history log.
 */
class CreateSalesBoard extends CreateRecord
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $breadcrumb = 'Nova Atualização';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-sales-board-form-page',
    ];

    /**
     * Position this update started from, used by the form to point out which
     * fields moved away from it.
     *
     * @var array<string, mixed>|null
     */
    public ?array $previousPosition = null;

    public function mount(): void
    {
        parent::mount();

        $sourceSalesBoard = $this->resolveSourceSalesBoard();

        if ($sourceSalesBoard === null) {
            return;
        }

        $this->previousPosition = $this->positionData($sourceSalesBoard);

        $this->form->fill([
            'emission_id' => $sourceSalesBoard->emission_id,
            'construction_id' => $sourceSalesBoard->construction_id,
            'reference_month' => $sourceSalesBoard->reference_month,
            'total_units' => $sourceSalesBoard->total_units,
            ...$this->positionData($sourceSalesBoard),
        ]);
    }

    public function getTitle(): string
    {
        return $this->previousPosition === null
            ? 'Adicionar Quadro de Vendas'
            : 'Nova Atualização do Quadro de Vendas';
    }

    public function getSubheading(): ?string
    {
        return 'Registre a posição mensal de unidades e valores do empreendimento por status.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar quadro de vendas')
            ->icon('heroicon-m-plus');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outro')
            ->color('gray');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Nova posição do quadro de vendas registrada.';
    }

    /**
     * Repeating a competence records a new version of that position instead of
     * a duplicate board, so the previous values stay untouched in the history.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return $this->recordPosition($data);
        } catch (SalesBoardRolloutException $exception) {
            /**
             * O guard do observer é quem recusa -- competência automatizada ou
             * quadro publicado. A tela só troca o erro cru pela explicação que o
             * domínio já escreveu, e desfaz a transação do formulário.
             */
            Notification::make()
                ->title('Registro manual recusado')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    /**
     * A competência é procurada por empreendimento e mês -- a mesma chave que o
     * {@see SalesBoardPositionReader} e a publicação do ciclo usam. Procurar
     * também pela Emissão deixava um quadro gravado sob outra Emissão passar
     * despercebido, e o registro virava um segundo quadro do mesmo
     * empreendimento no mesmo mês.
     *
     * Regravar uma competência existente é editar a posição vigente: exige a
     * permissão de edição, e não só a de criação que abriu esta página.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordPosition(array $data): Model
    {
        $referenceMonth = SalesBoard::normalizeReferenceMonth($data['reference_month'] ?? null);

        $existingSalesBoard = SalesBoard::query()
            ->where('construction_id', $data['construction_id'] ?? null)
            ->whereDate('reference_month', $referenceMonth)
            ->orderBy('id')
            ->first();

        if ($existingSalesBoard === null) {
            return parent::handleRecordCreation($data);
        }

        if (! SalesBoardResource::canEdit($existingSalesBoard)) {
            $this->refuse('Esta competência já tem posição registrada para o empreendimento, e alterá-la exige a permissão de editar o Quadro de Vendas.');
        }

        if ((int) $existingSalesBoard->emission_id !== (int) ($data['emission_id'] ?? 0)) {
            $this->refuse(sprintf(
                'A competência %s deste empreendimento já está registrada sob a operação %s, que não é a operação atual dele. '
                    .'Uma nova versão aqui criaria um segundo quadro para o mesmo mês; a divergência precisa ser corrigida antes.',
                SalesBoard::formatReferenceMonthForDisplay($referenceMonth),
                $existingSalesBoard->emission?->name ?? '—',
            ));
        }

        $existingSalesBoard->changeReason = $this->resolveChangeReason();
        $existingSalesBoard->update($data);

        return $existingSalesBoard;
    }

    /**
     * Recusa o registro com a explicação na tela e desfaz a transação do
     * formulário.
     */
    private function refuse(string $message): never
    {
        Notification::make()
            ->title('Registro recusado')
            ->body($message)
            ->danger()
            ->persistent()
            ->send();

        $this->halt(shouldRollbackDatabaseTransaction: true);
    }

    private function resolveChangeReason(): ?string
    {
        $changeReason = $this->data['change_reason'] ?? null;

        return filled($changeReason) ? trim((string) $changeReason) : null;
    }

    private function resolveSourceSalesBoard(): ?SalesBoard
    {
        $sourceId = request()->query('from');

        if (blank($sourceId)) {
            return null;
        }

        return SalesBoard::query()->whereKey($sourceId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function positionData(SalesBoard $salesBoard): array
    {
        return [
            'stock_units' => $salesBoard->stock_units,
            'financed_units' => $salesBoard->financed_units,
            'paid_units' => $salesBoard->paid_units,
            'exchanged_units' => $salesBoard->exchanged_units,
            'stock_value' => $salesBoard->stock_value,
            'financed_value' => $salesBoard->financed_value,
            'paid_value' => $salesBoard->paid_value,
            'exchanged_value' => $salesBoard->exchanged_value,
        ];
    }
}
