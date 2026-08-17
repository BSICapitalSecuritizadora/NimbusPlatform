<?php

namespace App\Enums;

enum ProposalContinuationAccessStatus: string
{
    case Revoked = 'revogado';
    case Expired = 'expirado';
    case Verified = 'validado';
    case Accessed = 'acessado';
    case Queued = 'aguardando_envio';
    case MailFailed = 'falha_envio';
    case Sent = 'enviado';

    public function label(): string
    {
        return match ($this) {
            self::Revoked => 'Revogado',
            self::Expired => 'Expirado',
            self::Verified => 'Validado',
            self::Accessed => 'Acessado',
            self::Queued => 'Aguardando envio',
            self::MailFailed => 'Falha no envio',
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
            self::Queued => 'warning',
            self::MailFailed => 'danger',
            self::Sent => 'primary',
        };
    }
}
