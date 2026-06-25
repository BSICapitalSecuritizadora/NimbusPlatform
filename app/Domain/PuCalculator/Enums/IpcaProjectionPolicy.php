<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Política de projeção do número-índice IPCA para meses SEM IPCA publicado.
 *
 * - PublishedOnly: só usa número-índice publicado; qualquer mês futuro sem publicação BLOQUEIA a curva.
 * - Market: permite usar número-índice PROJETADO (curva de mercado/ANBIMA) previamente cadastrado em
 *   `index_rates` com `is_projected = true`. A projeção nunca é silenciosa nem mascarada de publicada.
 */
enum IpcaProjectionPolicy: string
{
    case PublishedOnly = 'published_only';
    case Market = 'market';

    /**
     * Resolve a política a partir do valor persistido (string livre em `index_projection_policy`).
     * Default seguro: sem projeção (bloqueia meses sem publicação).
     */
    public static function fromParameter(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::PublishedOnly;
    }

    public function allowsProjection(): bool
    {
        return $this === self::Market;
    }

    public function label(): string
    {
        return match ($this) {
            self::PublishedOnly => 'Somente IPCA publicado',
            self::Market => 'Projeção de mercado (curva/ANBIMA)',
        };
    }
}
