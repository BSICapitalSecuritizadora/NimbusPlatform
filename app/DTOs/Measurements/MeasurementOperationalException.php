<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementOperationalExceptionType;
use App\Enums\MeasurementResponsibility;
use App\Models\Measurement;
use App\Models\Operation;
use App\Services\MeasurementWorkflow;

final readonly class MeasurementOperationalException
{
    public function __construct(
        public Measurement $measurement,
        public Operation $operation,
        public MeasurementOperationalExceptionType $type,
        public int $stage,
        public ?MeasurementResponsibility $expectedResponsibility,
        public ?string $configuredResponsibleName,
        public ?string $slaStatus,
    ) {}

    public function key(): string
    {
        return $this->measurement->getKey().'-'.$this->type->value;
    }

    public function operationLabel(): string
    {
        return collect([$this->operation->code, $this->operation->title])
            ->filter(fn (mixed $value): bool => filled($value))
            ->implode(' — ');
    }

    public function emissionLabel(): string
    {
        return $this->operation->emission?->name ?? 'Emissão não informada';
    }

    public function measurementLabel(): string
    {
        return filled($this->measurement->filename)
            ? (string) $this->measurement->filename
            : 'Medição '.$this->measurement->getKey();
    }

    public function competenceLabel(): string
    {
        return $this->measurement->reference_month?->format('m/Y') ?? 'Não informada';
    }

    public function stageLabel(): string
    {
        return MeasurementWorkflow::STAGE_LABELS[$this->stage] ?? 'Sem etapa acionável';
    }

    public function statusLabel(): string
    {
        return $this->measurement->status_label;
    }

    public function description(): string
    {
        return $this->type->operationalMessage($this->expectedResponsibility);
    }

    public function slaStatusLabel(): ?string
    {
        return match ($this->type) {
            MeasurementOperationalExceptionType::SlaNotConfigured => 'Não configurado',
            MeasurementOperationalExceptionType::SlaInvalidConfig => 'Configuração inválida',
            default => null,
        };
    }
}
