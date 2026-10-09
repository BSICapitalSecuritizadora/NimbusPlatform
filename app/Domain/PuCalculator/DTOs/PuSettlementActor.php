<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Models\User;
use InvalidArgumentException;

/**
 * Quem registra um fato de liquidação: uma pessoa (autorizada pelas permissões de
 * liquidação no próprio serviço) ou uma integração de sistema identificada pelo
 * nome. A integração só nasce em código do servidor -- nenhuma rota HTTP a
 * constrói a partir de entrada do usuário.
 */
final readonly class PuSettlementActor
{
    private function __construct(
        public ?User $user,
        public ?string $integration,
    ) {}

    public static function user(User $user): self
    {
        return new self($user, null);
    }

    public static function integration(string $name): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A integração que registra a liquidação precisa ser identificada.');
        }

        return new self(null, $name);
    }

    public function userId(): ?int
    {
        return $this->user?->getKey() !== null ? (int) $this->user->getKey() : null;
    }

    public function via(): string
    {
        return $this->integration ?? 'user';
    }
}
