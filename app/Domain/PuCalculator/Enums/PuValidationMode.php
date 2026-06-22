<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuValidationMode: string
{
    case DisplayScale = 'display-scale';
    case RawScale = 'raw-scale';
}
