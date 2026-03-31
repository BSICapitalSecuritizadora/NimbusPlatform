<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum ProposalStatus: string
{
    case AwaitingCompletion = 'aguardando_complementacao';
    case InReview = 'em_analise';
    case AwaitingInformation = 'aguardando_informacoes';
    case Approved = 'aprovado';
    case Rejected = 'rejeitado';
    case Completed = 'concluida';

    public function label(): string
    {
        return __('proposals.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingCompletion, self::AwaitingInformation => 'warning',
            self::InReview => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Completed => 'gray',
        };
    }

    public function canBeCompletedByRequester(): bool
    {
        return in_array($this, [
            self::AwaitingCompletion,
            self::AwaitingInformation,
        ], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }

    public static function labelFor(self|string|null $status): string
    {
        $originalStatus = $status;
        $status = self::fromValue($status);

        if ($status) {
            return $status->label();
        }

        if (blank($originalStatus)) {
            return __('proposals.status.unknown');
        }

        return Str::headline((string) $originalStatus);
    }

    public static function colorFor(self|string|null $status): string
    {
        return self::fromValue($status)?->color() ?? 'gray';
    }

    public static function fromValue(self|string|null $status): ?self
    {
        if ($status instanceof self) {
            return $status;
        }

        if (blank($status)) {
            return null;
        }

        return self::tryFrom((string) $status);
    }
}
