<?php

declare(strict_types=1);

namespace App\Support\Imports;

use App\Enums\ImportRowWarningCode;

/**
 * O que {@see SpreadsheetPlausibility} concluiu sobre um valor: no máximo um
 * erro, que bloqueia a linha, e os avisos, que só pedem atenção.
 */
final readonly class PlausibilityVerdict
{
    /**
     * @param  list<array{code: ImportRowWarningCode, message: string}>  $warnings
     */
    public function __construct(
        public ?string $error = null,
        public array $warnings = [],
    ) {}

    public static function plausible(): self
    {
        return new self;
    }

    public static function impossible(string $error): self
    {
        return new self(error: $error);
    }

    public static function doubtful(ImportRowWarningCode $code, string $message): self
    {
        return new self(warnings: [['code' => $code, 'message' => $message]]);
    }

    /**
     * Os dois veredictos juntos: o primeiro erro vence e os avisos se somam.
     */
    public function and(self $other): self
    {
        return new self(
            error: $this->error ?? $other->error,
            warnings: [...$this->warnings, ...$other->warnings],
        );
    }

    public function isImpossible(): bool
    {
        return $this->error !== null;
    }
}
