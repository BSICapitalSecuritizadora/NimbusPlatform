<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\BuilderReviewerType;
use App\Models\User;

/**
 * Quem respondeu a validação, na forma que o domínio precisa conhecer.
 *
 * A regra de negócio da revisão **não** chama `Auth::user()`. Essa dependência
 * amarraria o workflow ao mecanismo de login: no dia em que a construtora
 * acessar por outro caminho, a decisão de qual autenticação usar teria de ser
 * tomada dentro do serviço de submissão, que é o lugar errado para tomá-la.
 *
 * `stableKey` é o identificador que sobrevive: não é o id do usuário, é a chave
 * que ainda identifica o revisor se a conta for desativada ou trocada. Nome e
 * e-mail são congelados na submissão pelo mesmo motivo -- auditoria de validação
 * não pode depender de a conta continuar existindo.
 */
readonly class BuilderReviewerIdentity extends BaseDTO
{
    public function __construct(
        public BuilderReviewerType $type,
        public string $stableKey,
        public string $displayName,
        public ?string $email = null,
        public ?int $internalUserId = null,
    ) {}

    /**
     * Um operador interno exercitando o fluxo da construtora.
     *
     * É o único produtor de identidade nesta fase, e o tipo diz isso
     * explicitamente: uma submissão registrada internamente nunca deve ser lida
     * como uma submissão que a construtora fez por conta própria.
     */
    public static function forInternalUser(User $user): self
    {
        return new self(
            type: BuilderReviewerType::InternalPreview,
            stableKey: 'user:'.$user->getKey(),
            displayName: (string) $user->name,
            email: $user->email,
            internalUserId: (int) $user->getKey(),
        );
    }

    public function isExternal(): bool
    {
        return $this->type === BuilderReviewerType::External;
    }
}
