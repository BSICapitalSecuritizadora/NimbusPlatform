<?php

namespace App\Support\Delegations;

/**
 * Slot de uma resolução só, preenchido pela decisão de workflow que a usou.
 *
 * Existe porque quem decide a ação e quem descreve a pendência precisam da
 * MESMA {@see ResponsibilityAuthorization}: sem isto, o consumidor teria de
 * resolvê-la de novo depois -- uma segunda consulta de delegação por pendência,
 * e a chance de as duas resoluções discordarem.
 *
 * O slot é preenchido só quando a decisão chega até a autorização, o que
 * preserva a ordem barata-primeiro (estado antes de autorização) das checagens.
 */
final class ResponsibilityAuthorizationCapture
{
    private ?ResponsibilityAuthorization $authorization = null;

    public function capture(ResponsibilityAuthorization $authorization): ResponsibilityAuthorization
    {
        return $this->authorization = $authorization;
    }

    public function captured(): ?ResponsibilityAuthorization
    {
        return $this->authorization;
    }
}
