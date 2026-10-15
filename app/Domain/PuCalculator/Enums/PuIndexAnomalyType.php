<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Por que uma observação recebida da fonte ficou em quarentena (Fase 8.1).
 *
 * Os quatro casos pedem coisas diferentes de quem revisa: o salto suspeito e a
 * divergência têm um valor que pode ser aprovado pelo caminho governado; o valor
 * fora do domínio e a entrada malformada não têm, e só podem ser recusados (o
 * valor certo entra pela importação ou pela correção, com a fonte conferida).
 */
enum PuIndexAnomalyType: string
{
    /** Dez vezes maior ou menor que a observação realizada anterior: suspeita de unidade errada. */
    case ImplausibleJump = 'implausible_jump';

    /** Recusado pela validação dura: fora do domínio financeiro, escala excedida ou data futura. */
    case DomainRejected = 'domain_rejected';

    /** A data já tem observação aceita com outro valor. */
    case ValueConflict = 'value_conflict';

    /** A entrada da resposta não tem data ou valor legíveis. */
    case MalformedEntry = 'malformed_entry';

    public function label(): string
    {
        return match ($this) {
            self::ImplausibleJump => 'Salto suspeito (possível erro de unidade)',
            self::DomainRejected => 'Fora do domínio financeiro',
            self::ValueConflict => 'Diverge do valor registrado',
            self::MalformedEntry => 'Resposta malformada da fonte',
        };
    }

    /**
     * Só há o que aprovar quando a fonte entregou um valor legível.
     */
    public function canBeApproved(): bool
    {
        return in_array($this, [self::ImplausibleJump, self::ValueConflict], true);
    }
}
