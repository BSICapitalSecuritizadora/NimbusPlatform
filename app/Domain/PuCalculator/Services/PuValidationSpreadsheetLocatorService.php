<?php

namespace App\Domain\PuCalculator\Services;

use InvalidArgumentException;

class PuValidationSpreadsheetLocatorService
{
    /**
     * @var list<string>
     */
    private const DIRECTORIES = [
        'docs/samples/pu-validation',
        'docs/design',
    ];

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->availableFiles() as $path) {
            $options[$this->selectionKey($path)] = basename($path);
        }

        return $options;
    }

    public function resolve(string $selection): string
    {
        foreach ($this->availableFiles() as $path) {
            if ($this->selectionKey($path) === $selection || basename($path) === $selection) {
                return $path;
            }
        }

        throw new InvalidArgumentException(sprintf('Validation spreadsheet [%s] could not be resolved.', $selection));
    }

    public function findByKeyword(string $keyword): string
    {
        $normalizedKeyword = mb_strtolower($keyword);

        foreach ($this->availableFiles() as $path) {
            if (str_contains(mb_strtolower(basename($path)), $normalizedKeyword)) {
                return $path;
            }
        }

        throw new InvalidArgumentException(sprintf('Validation spreadsheet containing [%s] was not found.', $keyword));
    }

    /**
     * @return list<string>
     */
    public function availableFiles(): array
    {
        $filesByName = [];

        foreach (self::DIRECTORIES as $directory) {
            $absoluteDirectory = base_path($directory);

            if (! is_dir($absoluteDirectory)) {
                continue;
            }

            $matches = glob($absoluteDirectory.'/*.xlsx') ?: [];

            foreach ($matches as $path) {
                $filesByName[basename($path)] ??= $path;
            }
        }

        ksort($filesByName);

        return array_values($filesByName);
    }

    private function selectionKey(string $path): string
    {
        $normalizedBasePath = str_replace('\\', '/', base_path());
        $normalizedPath = str_replace('\\', '/', $path);

        return ltrim(str_replace($normalizedBasePath, '', $normalizedPath), '/');
    }
}
