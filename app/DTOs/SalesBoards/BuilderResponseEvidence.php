<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardBuilderResponseChannel;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * A resposta da construtora que acompanha o envio interno da validação.
 *
 * Tipada antes de chegar ao serviço, mas sem validar: o que falta ou está
 * errado é recusado pelo serviço de envio, com a mensagem do domínio, e não
 * aqui. É o que impede o contorno pela tela -- uma chamada direta ao serviço
 * passa pelas mesmas regras.
 *
 * A data é dia civil de negócio. O seletor do formulário guarda
 * `Y-m-d H:i:s`, e só o dia interessa: a hora seria a do clique, não a do
 * recebimento.
 */
readonly class BuilderResponseEvidence extends BaseDTO
{
    /**
     * @param  list<UploadedFile>  $files
     */
    public function __construct(
        public ?string $respondentName,
        public ?string $respondentEmail,
        public ?SalesBoardBuilderResponseChannel $channel,
        public ?CarbonImmutable $receivedOn,
        public array $files,
    ) {}

    /**
     * Monta a evidência a partir do estado do formulário de envio.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromFormState(array $data): self
    {
        $channel = $data['builder_response_channel'] ?? null;

        return new self(
            respondentName: self::nullableString($data['builder_respondent_name'] ?? null),
            respondentEmail: self::nullableString($data['builder_respondent_email'] ?? null),
            channel: $channel instanceof SalesBoardBuilderResponseChannel
                ? $channel
                : SalesBoardBuilderResponseChannel::tryFrom((string) $channel),
            receivedOn: self::businessDate($data['builder_response_received_on'] ?? null),
            files: array_values(array_filter(
                (array) ($data['builder_response_attachments'] ?? []),
                fn (mixed $file): bool => $file instanceof UploadedFile,
            )),
        );
    }

    private static function businessDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value->format('Y-m-d'))->startOfDay();
        }

        if (blank($value) || ! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr($value, 0, 10))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
