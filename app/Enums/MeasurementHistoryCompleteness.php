<?php

namespace App\Enums;

enum MeasurementHistoryCompleteness: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Insufficient = 'insufficient';

    public function combine(self $other): self
    {
        return self::worst($this, $other);
    }

    public static function worst(self ...$levels): self
    {
        $worst = self::Complete;

        foreach ($levels as $level) {
            if ($level->severity() > $worst->severity()) {
                $worst = $level;
            }
        }

        return $worst;
    }

    private function severity(): int
    {
        return match ($this) {
            self::Complete => 0,
            self::Partial => 1,
            self::Insufficient => 2,
        };
    }
}
