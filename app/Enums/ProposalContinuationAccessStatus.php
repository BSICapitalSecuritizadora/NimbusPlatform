<?php

namespace App\Enums;

enum ProposalContinuationAccessStatus: string
{
    case Revoked = 'revogado';
    case Expired = 'expirado';
    case Verified = 'validado';
    case Accessed = 'acessado';
    case Sent = 'enviado';

    public function label(): string
    {
        return match ($this) {
            self::Revoked => 'Revogado',
            self::Expired => 'Expirado',
            self::Verified => 'Validado',
            self::Accessed => 'Acessado',
            self::Sent => 'Enviado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Verified => 'success',
            self::Accessed => 'info',
            self::Expired => 'warning',
            self::Revoked => 'gray',
            self::Sent => 'primary',
        };
    }
}
