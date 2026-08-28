<?php

namespace App\Support\Delegations;

use App\Enums\MeasurementResponsibility;
use App\Models\ResponsibilityDelegation;

/**
 * The slice of authority a delegation scope can confer: which operations, and
 * which responsibilities inside them.
 *
 * The three formal scopes -- `global`, `operation`, `stage` -- are not closed
 * under intersection: `operation X` met with `stage 1` is neither of the three.
 * Reducing every scope to the same two axes makes the meet total, so one
 * abstraction answers "podem conferir autoridade sobre o mesmo contexto?" for
 * every pair, and a delegation path can carry a single accumulated context
 * instead of a special case per combination of scope types.
 *
 * `null` on an axis means unbounded -- every operation, every responsibility.
 * Responsibility is not a scope of its own: it is the refinement `scope_stage`
 * + `scope_responsibility` inside `stage`, canonicalized here exactly as
 * {@see ResponsibilityDelegation::covers()} canonicalizes it, so that a scope
 * confers here precisely what it authorizes there.
 *
 * A scope that confers nothing -- a `scope_responsibility` that is not a
 * canonical value, an unknown `scope_type` -- becomes an empty responsibility
 * set and therefore intersects nothing. Fail-closed on both sides: it grants no
 * authority, so it can neither close a cycle nor conflict with another scope.
 */
final class DelegationAuthorityContext
{
    /**
     * @param  ?int  $operationId  null significa "qualquer operação"
     * @param  ?list<string>  $responsibilities  null significa "qualquer responsabilidade"
     */
    private function __construct(
        public readonly ?int $operationId,
        public readonly ?array $responsibilities,
    ) {}

    public static function fromDelegation(ResponsibilityDelegation $delegation): self
    {
        return self::fromScope(
            (string) $delegation->scope_type,
            $delegation->scope_operation_id === null ? null : (int) $delegation->scope_operation_id,
            $delegation->scope_stage === null ? null : (int) $delegation->scope_stage,
            $delegation->scope_responsibility,
        );
    }

    /**
     * @param  array<string, mixed>  $data  payload já normalizado por ResponsibilityDelegationService::normalize()
     */
    public static function fromNormalizedData(array $data): self
    {
        return self::fromScope(
            (string) ($data['scope_type'] ?? ''),
            isset($data['scope_operation_id']) ? (int) $data['scope_operation_id'] : null,
            isset($data['scope_stage']) ? (int) $data['scope_stage'] : null,
            $data['scope_responsibility'] ?? null,
        );
    }

    public static function fromScope(
        string $scopeType,
        ?int $operationId,
        ?int $stage,
        ?string $responsibility,
    ): self {
        return match ($scopeType) {
            ResponsibilityDelegation::SCOPE_GLOBAL => new self(null, null),
            ResponsibilityDelegation::SCOPE_OPERATION => new self($operationId, null),
            ResponsibilityDelegation::SCOPE_STAGE => new self(
                $operationId,
                self::stageResponsibilities($stage, $responsibility),
            ),
            default => new self($operationId, []),
        };
    }

    /**
     * The authority both scopes can confer at once, or null when there is none.
     */
    public function intersect(self $other): ?self
    {
        if ($this->operationId !== null
            && $other->operationId !== null
            && $this->operationId !== $other->operationId) {
            return null;
        }

        $responsibilities = match (true) {
            $this->responsibilities === null => $other->responsibilities,
            $other->responsibilities === null => $this->responsibilities,
            default => array_values(array_intersect($this->responsibilities, $other->responsibilities)),
        };

        if ($responsibilities === []) {
            return null;
        }

        return new self($this->operationId ?? $other->operationId, $responsibilities);
    }

    /**
     * Whether the two scopes can confer authority over the same context.
     */
    public function intersects(self $other): bool
    {
        return $this->intersect($other) instanceof self;
    }

    /**
     * Stable identity of the accumulated context, so a graph walk can tell a
     * state it has already explored from one it has not.
     */
    public function signature(): string
    {
        if ($this->responsibilities === null) {
            $responsibilities = '*';
        } else {
            $sorted = $this->responsibilities;
            sort($sorted);
            $responsibilities = implode(',', $sorted);
        }

        return ($this->operationId ?? '*').'#'.$responsibilities;
    }

    /**
     * @return list<string>
     */
    private static function stageResponsibilities(?int $stage, ?string $responsibility): array
    {
        if ($stage === null) {
            return [];
        }

        // `null` recai na responsabilidade primária da etapa. Qualquer outro
        // valor é comparado literalmente -- inclusive string vazia, que não é
        // estado canônico e não cobre nada.
        if ($responsibility !== null) {
            $resolved = MeasurementResponsibility::tryFrom($responsibility);

            return $resolved instanceof MeasurementResponsibility && $resolved->stage() === $stage
                ? [$resolved->value]
                : [];
        }

        $primary = MeasurementResponsibility::primaryForStage($stage);

        return $primary instanceof MeasurementResponsibility ? [$primary->value] : [];
    }
}
