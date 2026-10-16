<?php

namespace App\Exceptions\Funds;

use RuntimeException;

/**
 * Domain error for the governed Conta Azul reconciliation workflow.
 *
 * Messages are user-safe Portuguese strings: they describe the violated
 * reconciliation rule without leaking identifiers, payloads or internals,
 * and are raised before any write (or inside a rolled-back transaction).
 * Like its Phase 3A sibling, this exception never carries secrets.
 */
class ContaAzulReconciliationException extends RuntimeException
{
    public static function forbidden(string $action): self
    {
        return new self("Você não tem permissão para {$action}.");
    }

    public static function billNotReady(string $status): self
    {
        return new self("Este título não pode ser atribuído no estado '{$status}'. Conclua a revisão antes de atribuir.");
    }

    public static function alreadyAttributed(): self
    {
        return new self('Este título já possui uma atribuição vigente. Use a correção governada para alterá-la.');
    }

    public static function notAttributed(): self
    {
        return new self('Este título ainda não possui atribuição. Atribua antes de corrigir.');
    }

    public static function staleSourceVersion(): self
    {
        return new self('O título mudou na fonte desde o início da revisão. Reabra a evidência atualizada antes de decidir.');
    }

    public static function staleDecision(): self
    {
        return new self('Outro usuário registrou uma decisão mais recente para este título. Reabra a revisão antes de continuar.');
    }

    public static function incompleteImport(): self
    {
        return new self('Este título ainda não foi observado por uma importação completa e validada. Conclua a importação ou registre uma exceção justificada.');
    }

    public static function reasonRequired(int $minLength): self
    {
        return new self("Registre o motivo da decisão com ao menos {$minLength} caracteres.");
    }

    public static function exceptionalAttributionRequiresJustification(): self
    {
        return new self('A emissão escolhida não está vinculada à conta deste título. Atribuições excepcionais exigem justificativa explícita e confirmação.');
    }

    public static function emissionNotAccessible(): self
    {
        return new self('A emissão escolhida não está acessível para este usuário.');
    }

    public static function invalidTransition(string $detail): self
    {
        return new self("Transição de conciliação inválida: {$detail}.");
    }

    public static function batchNotRecoverable(string $status): self
    {
        return new self("Somente importações interrompidas em execução podem ser recuperadas. Esta está '{$status}'.");
    }

    public static function batchActive(): self
    {
        return new self('Esta importação apresenta atividade recente e pode estar em execução. Aguarde ou confirme o abandono antes de recuperar.');
    }
}
