<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use RuntimeException;

/**
 * Lançada quando a segregação maker/checker é violada: o mesmo usuário que gerou/validou a curva
 * (ou importou a série projetada) tenta homologá-la/aprová-la sem ser super admin.
 */
class PuMakerCheckerException extends RuntimeException {}
