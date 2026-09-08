<?php

namespace App\Enums;

/**
 * Quanto a mudança da fonte viva afeta o baseline já congelado.
 *
 * A distinção é o que a governança das fases seguintes vai usar para decidir
 * quando um recálculo é obrigatório e quando uma aprovação motivada basta:
 *
 * - `None`: a fonte material observada continua idêntica à que produziu o
 *   baseline. Nada a fazer;
 * - `SourceOnly`: a fonte mudou e o snapshot derivado dela continua igual. É
 *   mudança real -- alguém mexeu num fato material -- que não altera nenhum
 *   número, classificação, movimento ou conformidade congelados;
 * - `Material`: o snapshot derivado da fonte atual é diferente do congelado.
 *   Alguma unidade, contrato, valor, movimento ou conformidade mudou;
 * - `Blocking`: a fonte atual não passa mais no readiness. Não existe versão
 *   nova possível até o dado que falta ser resolvido -- e o baseline anterior
 *   continua válido como registro do que foi apurado quando foi apurado.
 */
enum SalesBoardStaleImpact: string
{
    case None = 'nenhum';

    case SourceOnly = 'somente_fonte';

    case Material = 'material';

    case Blocking = 'bloqueante';

    public function isStale(): bool
    {
        return $this !== self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem alterações',
            self::SourceOnly => 'Fonte alterada sem impacto',
            self::Material => 'Alterações materiais',
            self::Blocking => 'Fonte atual incompleta',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'success',
            self::SourceOnly => 'info',
            self::Material => 'warning',
            self::Blocking => 'danger',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::None => 'A fonte material continua exatamente igual à que produziu esta versão.',
            self::SourceOnly => 'Algum fato material da fonte mudou, mas a posição derivada dele continua idêntica à congelada.',
            self::Material => 'A posição derivada da fonte atual difere da congelada.',
            self::Blocking => 'A fonte atual não está pronta para gerar uma nova versão: há dados faltando.',
        };
    }
}
