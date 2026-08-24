<?php

namespace App\Rules;

use App\Enums\ClientPersonType;
use App\Models\Client;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Single source of truth for a client document.
 *
 * Used by the form and by the spreadsheet import alike, so neither flow can
 * accept what the other rejects. It checks, in order: the document is present,
 * it matches the length of the selected person type, its check digits are
 * valid, and nobody else already holds it -- soft deleted clients included.
 */
class ClientDocument implements ValidationRule
{
    public function __construct(
        private readonly ?ClientPersonType $personType,
        private readonly mixed $ignoreClientId = null,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = $this->check($value);

        if ($message !== null) {
            $fail($message);
        }
    }

    /**
     * Returns the failure message, or null when the document is acceptable.
     */
    public function check(mixed $value): ?string
    {
        return $this->checkFormat($value) ?? $this->duplicateMessage(Str::digitsOnly((string) $value));
    }

    /**
     * Type coherence and check digits only.
     *
     * Kept separate from the duplicate lookup so the import can tell a malformed
     * document apart from a well-formed one that is already registered.
     */
    public function checkFormat(mixed $value): ?string
    {
        if ($this->personType === null) {
            return 'Selecione o tipo de pessoa antes de informar o documento.';
        }

        $digits = Str::digitsOnly((string) $value);

        if ($digits === '') {
            return 'Informe o '.$this->personType->documentLabel().'.';
        }

        if (strlen($digits) !== $this->personType->documentLength()) {
            return sprintf(
                '%s exige um %s com %d dígitos.',
                $this->personType->label(),
                $this->personType->documentLabel(),
                $this->personType->documentLength(),
            );
        }

        $checkDigitFailure = null;

        $this->personType->documentRule()->validate(
            'document',
            $digits,
            function (string $message) use (&$checkDigitFailure): void {
                $checkDigitFailure = $message;
            },
        );

        return $checkDigitFailure;
    }

    /**
     * Message when the document already belongs to somebody, soft deleted
     * clients included.
     */
    public function duplicateMessage(string $digits): ?string
    {
        $existingClient = Client::findByDocument($digits, $this->ignoreClientId);

        if ($existingClient === null) {
            return null;
        }

        $documentLabel = $this->personType->documentLabel();

        if ($existingClient->trashed()) {
            return "Já existe um cliente excluído cadastrado com este {$documentLabel}. Restaure o cadastro existente em vez de criar um novo.";
        }

        return "Já existe um cliente cadastrado com este {$documentLabel}.";
    }
}
