<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use RuntimeException;

/**
 * Tentativa de levar uma curva calculada em perfil NÃO operacional para um caminho
 * operacional (persistência da curva oficial, candidate, homologação, promoção).
 *
 * `PuCalculationProfile::LegacyCompatibility` existe apenas para reconciliar o Nimbus
 * com a planilha/sistema anterior e reproduz metodologia histórica, não a regra do Termo
 * de Securitização. Uma linha calculada sob ele nunca pode virar dado operacional --
 * ainda que por engano de um caminho futuro.
 *
 * A engine falha FECHADO: em vez de reetiquetar a curva como contratual (o que esconderia
 * a metodologia usada), ela recusa a escrita e exige que o chamador refaça o cálculo no
 * perfil contratual.
 */
class PuNonOperationalProfileException extends RuntimeException {}
