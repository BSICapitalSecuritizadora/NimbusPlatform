<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Perfil de cálculo da Calculadora de PU.
 *
 * `Contractual` é a AUTORIDADE do Nimbus: é a regra literal do Termo de
 * Securitização, é o default de toda chamada e é o único perfil que pode sair
 * da simulação (persistência, candidate, promoção, Gate C, homologação).
 *
 * `LegacyCompatibility` existe SOMENTE para reconciliar a curva com o sistema
 * anterior/planilha. Ele reproduz a metodologia histórica comprovada, não a
 * regra contratual, e por isso `isOperational()` é falso: nenhum caminho
 * operacional aceita este perfil.
 *
 * O perfil não é persistido em lugar nenhum -- vive na sessão da simulação.
 */
enum PuCalculationProfile: string
{
    case Contractual = 'contractual';

    case LegacyCompatibility = 'legacy_compatibility';

    /**
     * O default é sempre o contratual. Nenhum caminho existente muda de
     * comportamento por omissão.
     */
    public static function default(): self
    {
        return self::Contractual;
    }

    /**
     * Resolve um valor vindo da interface. Ausente, vazio OU desconhecido cai
     * no contratual: o legado só é alcançado por escolha explícita e válida,
     * nunca por string malformada.
     */
    public static function fromNullable(?string $value): self
    {
        $normalized = $value !== null ? trim($value) : '';

        if ($normalized === '') {
            return self::default();
        }

        return self::tryFrom($normalized) ?? self::default();
    }

    public function isContractual(): bool
    {
        return $this === self::Contractual;
    }

    public function isLegacyCompatibility(): bool
    {
        return $this === self::LegacyCompatibility;
    }

    /**
     * Um perfil operacional pode alimentar persistência, candidate, promoção,
     * Gate C e homologação. Só o contratual é.
     */
    public function isOperational(): bool
    {
        return $this === self::Contractual;
    }

    /**
     * Divergência metodológica comprovada contra a planilha, lado do Fator DI:
     * o contrato manda considerar o Fator DI com 8 casas ANTES de combinar com
     * o Fator Spread; o sistema legado leva o produtório acumulado (16 casas)
     * direto para a combinação e só quantiza no Fator de Juros, em 9 casas.
     *
     * Evidência em 31/08/2026: a planilha exibe Fator DI 1,0077946312497466 e
     * produto 1,0112961217360983. `round8(1,0077946312497466) x 1,003474409`
     * daria 1,0112961207326..., que não é o produto observado -- logo o Fator DI
     * NÃO foi arredondado em 8 antes da combinação.
     *
     * Ver `PuLegacyReferenceFixture` e `PuLegacyCompatibilityTest`.
     */
    public function roundsIndexFactorForCombination(): bool
    {
        return $this === self::Contractual;
    }

    /**
     * Mesma divergência, lado do Fator Spread: o contrato o considera com 9
     * casas; o legado o carrega com a precisão integral e só quantiza depois.
     *
     * Evidência na mesma linha: com o Spread já arredondado em 9 casas o produto
     * seria 1,0112961219867..., e o observado é 1,0112961217360983. O valor
     * 1,003474409 que a planilha EXIBE é a apresentação em 9 casas de um fator
     * que ela carrega inteiro.
     *
     * Nos dois casos o Fator de Juros continua arredondado em 9 casas -- é o que
     * faz os juros da planilha caírem em múltiplos exatos de 1e-6 por unidade.
     */
    public function roundsSpreadFactor(): bool
    {
        return $this === self::Contractual;
    }

    /**
     * O prêmio dos Dias Úteis anteriores à Data de Integralização é exigência
     * do Termo e o sistema legado de referência não o contempla. Removê-lo é,
     * portanto, parte da metodologia histórica -- e só dela.
     */
    public function appliesFirstCouponPreIntegralizationPremium(): bool
    {
        return $this === self::Contractual;
    }

    public function label(): string
    {
        return match ($this) {
            self::Contractual => 'Contratual',
            self::LegacyCompatibility => 'Compatibilidade com sistema legado',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Contractual => 'Regra documental da operação',
            self::LegacyCompatibility => 'Reproduz metodologia histórica para reconciliação',
        };
    }

    /** Aviso exibido enquanto o perfil está ativo. Nulo no contratual. */
    public function warning(): ?string
    {
        return match ($this) {
            self::Contractual => null,
            self::LegacyCompatibility => 'Compatibilidade com sistema legado. Este modo existe para reconciliação e pode reproduzir metodologia de precisão diferente da regra contratual.',
        };
    }

    /** Aviso adicional na linha do primeiro cupom. Nulo no contratual. */
    public function firstCouponWarning(): ?string
    {
        return match ($this) {
            self::Contractual => null,
            self::LegacyCompatibility => 'O sistema legado de referência não contempla o prêmio contratual de 2 DU identificado para o primeiro pagamento.',
        };
    }

    /** @return array<string, string> value => label, para selects da interface */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
