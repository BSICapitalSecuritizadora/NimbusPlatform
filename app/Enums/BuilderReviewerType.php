<?php

namespace App\Enums;

/**
 * De onde veio a pessoa que respondeu a validação.
 *
 * O domínio da revisão não sabe -- e não deve saber -- como a construtora chega
 * até a tela. Se amanhã o acesso externo for por SSO, por link assinado ou por
 * um portal dedicado, nada aqui muda: o que a revisão precisa é de uma
 * identidade estável para congelar na auditoria.
 *
 * Nesta fase só `InternalPreview` é produzível: não existe superfície externa, e
 * toda validação registrada foi feita por alguém de dentro exercitando o fluxo.
 * `External` está declarado porque o vocabulário já está fechado, mas nenhum
 * caminho de código o produz -- e é isso que impede uma submissão interna de se
 * passar por uma submissão da construtora.
 */
enum BuilderReviewerType: string
{
    case InternalPreview = 'internal_preview';

    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::InternalPreview => 'Registrada internamente',
            self::External => 'Enviada pela construtora',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::InternalPreview => 'gray',
            self::External => 'success',
        };
    }
}
