<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use RuntimeException;

/**
 * Lançada quando a segregação maker/checker é violada: o mesmo usuário que gerou/validou a curva
 * (ou importou a série projetada) tenta homologá-la/aprová-la. Na curva, a exceção é o responsável
 * pela área Curva de PU (ver `HomologatePuCurve::selfHomologationBlocker()`).
 */
class PuMakerCheckerException extends RuntimeException {}
