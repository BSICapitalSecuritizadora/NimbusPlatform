<?php

namespace App\Filament\Resources\MeasurementExceptions\Pages;

use App\DTOs\Measurements\MeasurementOperationalException;
use App\DTOs\Measurements\MeasurementOperationalExceptionResult;
use App\Enums\AccessPermission;
use App\Enums\MeasurementOperationalExceptionType;
use App\Filament\Resources\MeasurementExceptions\MeasurementExceptionResource;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Operations\OperationResource;
use App\Models\User;
use App\Services\MeasurementOperationalExceptionReadModel;
use App\Services\MeasurementWorkflow;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ListMeasurementExceptions extends Page
{
    use WithPagination;

    protected static string $resource = MeasurementExceptionResource::class;

    protected string $view = 'filament.resources.measurement-exceptions.pages.list-measurement-exceptions';

    protected static ?string $title = 'Exceções Operacionais';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'operation', except: '')]
    public string $operationId = '';

    #[Url(as: 'emission', except: '')]
    public string $emissionId = '';

    #[Url(as: 'competence_from', except: '')]
    public string $competenceFrom = '';

    #[Url(as: 'competence_to', except: '')]
    public string $competenceTo = '';

    #[Url(as: 'stage', except: '')]
    public string $stage = '';

    #[Url(as: 'exception', except: '')]
    public string $exceptionType = '';

    public int $perPage = 25;

    private ?MeasurementOperationalExceptionResult $cachedResult = null;

    public function getSubheading(): ?string
    {
        return 'Configurações estruturais que podem impedir o avanço das medições visíveis na sua carteira.';
    }

    public function updated(string $property): void
    {
        if (! in_array($property, [
            'search',
            'operationId',
            'emissionId',
            'competenceFrom',
            'competenceTo',
            'stage',
            'exceptionType',
        ], true)) {
            return;
        }

        $this->filtersChanged();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search',
            'operationId',
            'emissionId',
            'competenceFrom',
            'competenceTo',
            'stage',
            'exceptionType',
        ]);

        $this->filtersChanged();
    }

    public function filterByException(string $exceptionType): void
    {
        $type = MeasurementOperationalExceptionType::tryFrom($exceptionType);

        if (! $type instanceof MeasurementOperationalExceptionType
            || ($this->exceptionType !== '' && $this->exceptionType !== $type->value)) {
            return;
        }

        $this->exceptionType = $type->value;
        $this->filtersChanged();
    }

    public function canDrillDown(MeasurementOperationalExceptionType $type): bool
    {
        return $this->exceptionType === '' || $this->exceptionType === $type->value;
    }

    public function result(): MeasurementOperationalExceptionResult
    {
        if ($this->cachedResult instanceof MeasurementOperationalExceptionResult) {
            return $this->cachedResult;
        }

        $actor = auth()->user();
        abort_unless($actor instanceof User && MeasurementExceptionResource::canViewAny(), 403);

        return $this->cachedResult = $this->readModel()->scanFor(
            $actor,
            $this->filters(),
            $this->search,
            (int) $this->getPage(),
            $this->perPage,
        );
    }

    /** @return LengthAwarePaginator<int, MeasurementOperationalException> */
    public function exceptions(): LengthAwarePaginator
    {
        return $this->result()->paginator(
            MeasurementExceptionResource::getUrl('index'),
            $this->queryParameters(),
        );
    }

    /** @return array<int, string> */
    public function operationOptions(): array
    {
        $actor = auth()->user();

        return $actor instanceof User ? $this->readModel()->operationOptionsFor($actor) : [];
    }

    /** @return array<int, string> */
    public function emissionOptions(): array
    {
        $actor = auth()->user();

        return $actor instanceof User ? $this->readModel()->emissionOptionsFor($actor) : [];
    }

    /** @return array<int, string> */
    public function stageOptions(): array
    {
        return MeasurementWorkflow::STAGE_LABELS;
    }

    /** @return array<string, string> */
    public function exceptionTypeOptions(): array
    {
        return MeasurementOperationalExceptionType::options();
    }

    /**
     * O escopo do registro já foi aplicado por Measurement::visibleTo() ao
     * materializar a linha. O destino repete a própria verificação de policy.
     */
    public function measurementUrl(MeasurementOperationalException $exception): ?string
    {
        if (! MeasurementResource::canViewAny()) {
            return null;
        }

        return MeasurementResource::getUrl('view', ['record' => $exception->measurement]);
    }

    /**
     * A operação é a fonte da visibilidade canônica da Measurement materializada.
     * O destino repete a própria verificação de policy.
     */
    public function operationUrl(MeasurementOperationalException $exception): ?string
    {
        if (! OperationResource::canViewAny()) {
            return null;
        }

        return OperationResource::getUrl('view', ['record' => $exception->operation]);
    }

    public function manageResponsibilitiesUrl(MeasurementOperationalException $exception): ?string
    {
        $actor = auth()->user();

        if (! $exception->type->isResponsibilityConfiguration()
            || ! $actor instanceof User
            || ! OperationResource::canViewAny()
            || ! $actor->can(AccessPermission::OperationsUpdate->value)
            || ! Gate::allows('manageResponsibilities', $exception->operation)) {
            return null;
        }

        return OperationResource::getUrl('edit', ['record' => $exception->operation]);
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'operation_id' => $this->operationId,
            'emission_id' => $this->emissionId,
            'competence_from' => $this->competenceFrom,
            'competence_to' => $this->competenceTo,
            'stage' => $this->stage,
            'exception_type' => $this->exceptionType,
        ];
    }

    /** @return array<string, string> */
    private function queryParameters(): array
    {
        return array_filter([
            'q' => $this->search,
            'operation' => $this->operationId,
            'emission' => $this->emissionId,
            'competence_from' => $this->competenceFrom,
            'competence_to' => $this->competenceTo,
            'stage' => $this->stage,
            'exception' => $this->exceptionType,
        ], fn (string $value): bool => $value !== '');
    }

    private function filtersChanged(): void
    {
        $this->cachedResult = null;
        $this->resetPage();
    }

    private function readModel(): MeasurementOperationalExceptionReadModel
    {
        return app(MeasurementOperationalExceptionReadModel::class);
    }
}
