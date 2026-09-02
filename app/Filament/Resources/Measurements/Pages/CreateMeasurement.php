<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Exceptions\OperationLifecycleException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use App\Models\Operation;
use App\Services\MeasurementWorkflow;
use App\Services\OperationLifecycleService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateMeasurement extends CreateRecord
{
    protected static string $resource = MeasurementResource::class;

    protected static ?string $title = 'Enviar Medição';

    protected static ?string $breadcrumb = 'Enviar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-measurement-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Envie os arquivos da competência para cada empreendimento vinculado à operação.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();
        abort_unless($actor !== null, 403);

        $operation = Operation::query()
            ->visibleTo($actor)
            ->findOrFail($data['operation_id'] ?? null);
        Gate::authorize('createForOperation', [Measurement::class, $operation]);

        $data['uploaded_by'] = auth()->id();
        $data['uploaded_at'] = now();
        $data['status'] = 'pending';
        $data['current_stage'] = 1;

        return $data;
    }

    /**
     * A medição nasce sob o lock da própria operação.
     *
     * A autorização acima é feita fora de qualquer transação e, sozinha, deixaria
     * uma janela: entre ela e a inserção, outra requisição poderia concluir ou
     * cancelar a operação, e nasceria exatamente o estado que o lifecycle existe
     * para tornar inalcançável -- operação encerrada com medição aberta. Com o
     * lock, as duas transações competem pela mesma linha e apenas uma vence: ou a
     * medição existe antes e o encerramento é recusado por haver medição aberta,
     * ou o encerramento acontece antes e a criação é recusada aqui.
     *
     * A ordem é a de sempre no módulo: Operation primeiro.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor !== null, 403);

        return DB::transaction(function () use ($data, $actor): Model {
            try {
                app(OperationLifecycleService::class)->lockForNewMeasurement(
                    (int) ($data['operation_id'] ?? 0),
                    $actor,
                );
            } catch (OperationLifecycleException $exception) {
                // A recusa é do domínio, mas quem a lê está num formulário: ela
                // volta apontando para o campo que a causou.
                throw ValidationException::withMessages([
                    'data.operation_id' => $exception->getMessage(),
                ]);
            }

            return parent::handleRecordCreation($data);
        }, 3);
    }

    protected function afterCreate(): void
    {
        app(MeasurementWorkflow::class)->startReview($this->record->refresh(), auth()->user());
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Enviar Medição')
            ->icon('heroicon-m-arrow-up-tray');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outra')
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
        return 'Medição enviada e encaminhada para análise.';
    }
}
