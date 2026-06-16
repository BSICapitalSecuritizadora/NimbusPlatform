<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\ValueObjects;

use InvalidArgumentException;

final readonly class Decimal
{
    private string $value;

    public function __construct(string $value)
    {
        $normalizedValue = self::normalize($value);

        if (! is_numeric($normalizedValue)) {
            throw new InvalidArgumentException(sprintf('Invalid decimal value [%s].', $value));
        }

        $this->value = $normalizedValue;
    }

    public static function of(string|int|float $value): self
    {
        if (is_float($value)) {
            return new self(rtrim(rtrim(sprintf('%.16F', $value), '0'), '.'));
        }

        return new self((string) $value);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public static function one(): self
    {
        return new self('1');
    }

    public function value(): string
    {
        return $this->value;
    }

    public function add(self $other, int $scale = 16): self
    {
        return new self(bcadd($this->value, $other->value, $scale));
    }

    public function subtract(self $other, int $scale = 16): self
    {
        return new self(bcsub($this->value, $other->value, $scale));
    }

    public function multiply(self $other, int $scale = 16): self
    {
        return new self(bcmul($this->value, $other->value, $scale));
    }

    public function divide(self $other, int $scale = 16): self
    {
        if ($other->isZero($scale)) {
            throw new InvalidArgumentException('Division by zero is not allowed.');
        }

        return new self(bcdiv($this->value, $other->value, $scale));
    }

    public function powerInt(int $exponent, int $scale = 16): self
    {
        if ($exponent === 0) {
            return self::one();
        }

        if ($exponent < 0) {
            $positivePower = $this->powerInt(abs($exponent), $scale + 6);

            return self::one()->divide($positivePower, $scale);
        }

        return new self(bcpow($this->value, (string) $exponent, $scale));
    }

    public function compare(self $other, int $scale = 16): int
    {
        return bccomp($this->value, $other->value, $scale);
    }

    public function isZero(int $scale = 16): bool
    {
        return $this->compare(self::zero(), $scale) === 0;
    }

    public function isNegative(int $scale = 16): bool
    {
        return $this->compare(self::zero(), $scale) < 0;
    }

    public function absolute(int $scale = 16): self
    {
        if (! $this->isNegative($scale)) {
            return $this;
        }

        return self::zero()->subtract($this, $scale);
    }

    public function min(self $other, int $scale = 16): self
    {
        return $this->compare($other, $scale) <= 0 ? $this : $other;
    }

    public function max(self $other, int $scale = 16): self
    {
        return $this->compare($other, $scale) >= 0 ? $this : $other;
    }

    private static function normalize(string $value): string
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            return '0';
        }

        if (str_contains($normalizedValue, ',')) {
            $normalizedValue = str_replace('.', '', $normalizedValue);
            $normalizedValue = str_replace(',', '.', $normalizedValue);
        }

        return $normalizedValue;
    }
}
