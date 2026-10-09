<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Models\PuObligationRefreshRequest;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Agenda a atualização das obrigações de uma emissão para depois do commit da
 * transação corrente (fora de transação, na hora), uma vez por emissão a cada
 * commit: uma importação de cem linhas do cronograma informado vira um pedido e
 * uma atualização, não cem.
 *
 * Fase 6: o pedido é DURÁVEL. Ele é gravado na própria transação de quem pede
 * ({@see PuObligationRefreshRecovery::request()}); a tentativa logo depois do
 * commit é só a primeira. Se ela falhar, ou se o processo morrer antes de
 * executá-la, o pedido continua no banco e a varredura o retoma -- nenhum fato
 * que comitou fica sem a sua recomposição.
 *
 * Se a transação for desfeita, o pedido e o callback somem com ela; o próximo
 * pedido grava outro. Scoped: o que está pendente vale para uma requisição ou um
 * job e nunca vaza para o seguinte.
 *
 * A classe é aberta só para o teste que simula o processo morrendo entre o
 * commit e a primeira tentativa ({@see self::afterCommit()}).
 */
class PuObligationRefreshScheduler
{
    /** @var array<int, int> emissão => pedido gravado que aguarda a tentativa pós-commit */
    private array $pending = [];

    public function __construct(
        private readonly PuObligationRefreshRecovery $recovery,
    ) {}

    public function schedule(int $emissionId, string $trigger): void
    {
        // Já há um pedido desta emissão na transação corrente, esperando o commit.
        // Se a transação que o gravou foi desfeita, ele não existe mais e um novo é
        // gravado.
        if (isset($this->pending[$emissionId])
            && PuObligationRefreshRequest::query()->whereKey($this->pending[$emissionId])->exists()) {
            return;
        }

        $request = $this->recovery->request($emissionId, $trigger);
        $this->pending[$emissionId] = (int) $request->id;

        $this->afterCommit(function () use ($emissionId, $request): void {
            if (($this->pending[$emissionId] ?? null) === (int) $request->id) {
                unset($this->pending[$emissionId]);
            }

            $this->recovery->process($emissionId, PuObligationRefreshRecovery::VIA_AFTER_COMMIT);
        });
    }

    protected function afterCommit(Closure $callback): void
    {
        DB::afterCommit($callback);
    }
}
