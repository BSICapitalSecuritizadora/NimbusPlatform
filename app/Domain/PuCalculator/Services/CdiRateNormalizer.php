<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

final class CdiRateNormalizer
{
    /** @return array{value:?string,issue:?string} */
    public function fromB3EncodedValue(string $rawValue): array
    {
        $encodedValue = trim($rawValue);

        if ($encodedValue === '') {
            return ['value' => null, 'issue' => 'empty_file'];
        }

        if (preg_match('/^\d{9}$/', $encodedValue) !== 1) {
            return ['value' => null, 'issue' => 'invalid_b3_format'];
        }

        $integerPart = ltrim(substr($encodedValue, 0, 7), '0');
        $integerPart = $integerPart === '' ? '0' : $integerPart;

        return [
            'value' => sprintf('%s.%s', $integerPart, substr($encodedValue, -2)),
            'issue' => null,
        ];
    }

    /** @return array{value:?string,issue:?string} */
    public function fromPublishedDecimal(string $rawValue, int $officialScale = 2): array
    {
        $decimalValue = str_replace(',', '.', trim($rawValue));

        if ($decimalValue === '' || preg_match('/^\d+(?:\.\d+)?$/', $decimalValue) !== 1) {
            return ['value' => null, 'issue' => 'invalid_decimal_format'];
        }

        [$integerPart, $fractionalPart] = array_pad(explode('.', $decimalValue, 2), 2, '');

        if (strlen($fractionalPart) > $officialScale) {
            return ['value' => null, 'issue' => 'precision_exceeds_official_scale'];
        }

        $integerPart = ltrim($integerPart, '0');
        $integerPart = $integerPart === '' ? '0' : $integerPart;

        return [
            'value' => sprintf('%s.%s', $integerPart, str_pad($fractionalPart, $officialScale, '0')),
            'issue' => null,
        ];
    }
}
