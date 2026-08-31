<?php

namespace App\Enums;

/**
 * What actually authorized an actor to act over a measurement responsibility.
 *
 * Holding the `admin` or `super-admin` role is not a source: an administrator
 * who is also the direct responsible acts as the responsible, and one who holds
 * an effective delegation acts as the delegate. `AdminOverride` is reserved for
 * the case where the administrative bypass is the only thing that authorized the
 * action -- which is exactly what an audit needs to be able to tell apart.
 */
enum WorkflowAuthorizationSource: string
{
    case Direct = 'direct';
    case Delegated = 'delegated';
    case AdminOverride = 'admin_override';
    case None = 'none';

    public function authorizes(): bool
    {
        return $this !== self::None;
    }

    /**
     * O rótulo de auditoria.
     *
     * Override administrativo não é falha: é o registro de que a ação passou por
     * cima da responsabilidade, e é exatamente o que uma auditoria precisa
     * conseguir separar. O texto diz o que aconteceu, sem adjetivo.
     */
    public function auditLabel(): string
    {
        return match ($this) {
            self::Direct => 'Responsável direto',
            self::Delegated => 'Delegação',
            self::AdminOverride => 'Override administrativo',
            self::None => 'Não aplicável',
        };
    }

    public function auditColor(): string
    {
        return match ($this) {
            self::Direct => 'gray',
            self::Delegated => 'info',
            self::AdminOverride => 'warning',
            self::None => 'gray',
        };
    }

    /**
     * A origem gravada numa Activity de workflow.
     *
     * O JSON guarda dois booleanos porque foi assim que a P2.3 os gravou, e o
     * histórico já registrado não vai ser reescrito. A leitura é feita aqui, uma
     * vez, para que nenhuma tela precise saber que a origem mora em dois campos
     * -- e para que `admin_override` deixe de ser visível só para quem lê JSON.
     *
     * Devolve `null` quando a Activity não é de workflow e portanto não tem
     * origem a atribuir: log de importação não ganha um "Não aplicável" à toa.
     *
     * @param  array<string, mixed>|null  $properties
     */
    public static function fromActivityProperties(?array $properties): ?self
    {
        if ($properties === null
            || ! array_key_exists('admin_override', $properties)
            || ! array_key_exists('delegated', $properties)) {
            return null;
        }

        return match (true) {
            (bool) $properties['admin_override'] => self::AdminOverride,
            (bool) $properties['delegated'] => self::Delegated,
            default => self::Direct,
        };
    }
}
