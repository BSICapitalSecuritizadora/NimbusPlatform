<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;

/**
 * A operação pedida exige o PU operacionalmente ativo e ele não está (Fase 7):
 * o interruptor global do ambiente está desligado, ou a emissão não foi ativada,
 * ou foi suspensa.
 *
 * Recusa estruturada, não silêncio: nada é gravado, nenhuma curva é gerada por
 * fora e nada é tratado como falha de cálculo. Estende a recusa de governança
 * porque é assim que as telas e os comandos já mostram uma recusa de negócio.
 */
class PuOperationalActivationException extends PuCurveGovernanceException
{
    public const REASON_GLOBALLY_DISABLED = 'PU_OPERATIONS_GLOBALLY_DISABLED';

    public const REASON_NOT_CONFIGURED = 'PU_NOT_CONFIGURED';

    public const REASON_NOT_ACTIVATED = 'PU_OPERATIONS_NOT_ACTIVATED';

    public const REASON_SUSPENDED = 'PU_OPERATIONS_SUSPENDED';

    public const REASON_UNAUTHORIZED = 'PU_ACTIVATION_UNAUTHORIZED';

    public const REASON_INVALID_TRANSITION = 'PU_ACTIVATION_INVALID_TRANSITION';

    public const REASON_NOT_READY = 'PU_ACTIVATION_NOT_READY';

    public const REASON_MISSING_JUSTIFICATION = 'PU_ACTIVATION_MISSING_JUSTIFICATION';

    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $emissionId,
        public readonly ?PuOperationalActivationStatus $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'reason' => $this->reasonCode,
            'emission_id' => $this->emissionId,
            'activation_status' => $this->status?->value,
        ];
    }
}
