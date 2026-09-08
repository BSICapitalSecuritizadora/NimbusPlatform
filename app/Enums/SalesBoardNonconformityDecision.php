<?php

namespace App\Enums;

/**
 * O que a Gestão concluiu sobre uma pendência.
 *
 * As conclusões admissíveis dependem da origem, e a assimetria é deliberada:
 *
 * - uma **declaração da construtora** só pode terminar em "não procede" ou
 *   "correção necessária". Não existe terceira saída honesta: se a Gestão
 *   entende que a construtora tem razão, o fato congelado está errado e a fonte
 *   precisa ser corrigida. Chamar isso de "exceção aprovada" seria publicar
 *   conscientemente um número que a própria Gestão considera errado;
 *
 * - uma **venda fora da política** não pode ser dispensada como "não procede".
 *   O Nimbus congelou a comparação em centavos exatos contra a política vigente
 *   na data; o que existe é uma decisão comercial -- autorizar a exceção -- ou a
 *   constatação de que a política ou a fonte estavam erradas;
 *
 * - uma **venda sem conformidade determinável** só admite correção. Não dá para
 *   dispensá-la, porque não se sabe se procede; e não dá para excepcioná-la,
 *   porque uma exceção é concedida contra um limite, e o limite é justamente o
 *   que falta. Enquanto o dado não existir, a única afirmação verdadeira é que
 *   a fonte precisa ser regularizada.
 *
 * `Pending` não é decisão: é a ausência dela, e é o que bloqueia a aprovação
 * até que alguém se pronuncie.
 */
enum SalesBoardNonconformityDecision: string
{
    case Pending = 'pendente';

    case Dismissed = 'nao_procede';

    case AcceptedException = 'excecao_aprovada';

    case CorrectionRequired = 'correcao_necessaria';

    /**
     * As conclusões que uma pendência daquela origem admite.
     *
     * `Pending` entra na lista porque a Gestão pode voltar atrás enquanto a
     * análise é rascunho -- desfazer uma decisão precipitada não pode exigir
     * abrir outra rodada.
     *
     * @return list<self>
     */
    public static function allowedFor(SalesBoardNonconformityOrigin $origin): array
    {
        return match ($origin) {
            SalesBoardNonconformityOrigin::BuilderDeclared => [
                self::Pending,
                self::Dismissed,
                self::CorrectionRequired,
            ],
            SalesBoardNonconformityOrigin::SystemSaleNonConform => [
                self::Pending,
                self::AcceptedException,
                self::CorrectionRequired,
            ],
            /**
             * Uma só conclusão possível. A lista existe mesmo assim -- em vez de
             * um `if` na tela -- porque é ela que a UI, o validador e o teste
             * consultam, e uma exceção admitida por engano aqui apareceria nos
             * três de uma vez.
             */
            SalesBoardNonconformityOrigin::SystemSaleUndetermined => [
                self::Pending,
                self::CorrectionRequired,
            ],
        };
    }

    public function isAllowedFor(SalesBoardNonconformityOrigin $origin): bool
    {
        return in_array($this, self::allowedFor($origin), true);
    }

    /**
     * Uma conclusão exige motivo; a ausência de conclusão não.
     */
    public function requiresReason(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * A pendência continua esperando a Gestão.
     */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * A pendência impede a publicação enquanto não for reavaliada.
     */
    public function blocksApproval(): bool
    {
        return $this === self::Pending || $this === self::CorrectionRequired;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Dismissed => 'Não procede',
            self::AcceptedException => 'Exceção aprovada',
            self::CorrectionRequired => 'Correção necessária',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Dismissed => 'info',
            self::AcceptedException => 'warning',
            self::CorrectionRequired => 'danger',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pending => 'Ainda não analisada pela Gestão.',
            self::Dismissed => 'A Gestão entende que a declaração não procede e que a posição apurada está correta.',
            self::AcceptedException => 'Os fatos estão corretos e a Gestão autoriza a exceção à política comercial.',
            self::CorrectionRequired => 'A fonte precisa ser corrigida e a posição recalculada antes de qualquer publicação.',
        };
    }
}
