<?php

namespace App\Enums;

/**
 * O que a tentativa de remover uma data do Cronograma de Pagamentos produziu.
 *
 * `Unavailable` e `Changed` não são falhas: são a resposta correta quando outra
 * pessoa -- ou a importação, ou a projeção da curva de PU -- mexeu na linha
 * enquanto a confirmação estava aberta. Em nenhum dos dois casos algo é apagado.
 */
enum PaymentRemovalOutcome: string
{
    case Removed = 'removida';

    case Unavailable = 'indisponivel';

    case Changed = 'alterada';

    public function label(): string
    {
        return match ($this) {
            self::Removed => 'Data removida com sucesso.',
            self::Unavailable => 'Este registro não está mais disponível.',
            self::Changed => 'Esta data foi alterada enquanto a confirmação estava aberta.',
        };
    }
}
