<?php

declare(strict_types=1);

namespace App\Listeners\Guarantees;

use App\Events\PuCalculator\EmissionPuSourceChanged;
use App\Services\Guarantees\GuaranteeSnapshotOutstandingBalanceInvalidator;
use Throwable;

/**
 * Liga a troca da fonte de PU às competências de garantias sem que o PU saiba
 * que garantias existem.
 *
 * Marca as competências já encerradas no calendário de negócio cujo saldo
 * devedor gravado deixou de ser o que a fonte responde agora -- as mesmas que a
 * verificação diária confere. A do mês em curso fica de fora: o saldo dela é o
 * último PU dentro do mês, que muda a cada dia novo de PU, e a comparação não
 * separa essa variação da que a troca de fonte causou. Marcá-la levaria à
 * linha, à trilha protegida, ao selo e ao alerta o motivo do evento ("Curva de
 * PU vX homologada") para uma diferença que o ato não produziu. Ela não fica
 * sem defesa: o fechamento apura a competência de novo, e, depois que o mês
 * termina, a verificação diária encontra o que tiver ficado para trás.
 *
 * Registrado só pelo event discovery do Laravel, por morar em `app/Listeners`.
 * Um `Event::listen()` a mais no provider faria o ouvinte rodar duas vezes.
 *
 * O invalidador chega pelo construtor, e o ouvinte nasce do container a cada
 * disparo: o leitor de PU por trás dele memoriza a versão oficial por
 * instância, e uma instância nova enxerga a curva que acabou de ser homologada.
 */
class MarkGuaranteeCompetencesOnPuSourceChange
{
    public function __construct(
        private readonly GuaranteeSnapshotOutstandingBalanceInvalidator $invalidator,
    ) {}

    /**
     * Uma falha aqui não sobe.
     *
     * O ouvinte roda depois do commit: a homologação, a invalidação ou a
     * importação já foram gravadas, e deixar a exceção chegar a quem as fez
     * mostraria um erro para um ato concluído. A falha é registrada, e nas
     * competências já encerradas a verificação diária encontra a mesma
     * diferença no dia seguinte.
     */
    public function handle(EmissionPuSourceChanged $event): void
    {
        try {
            $this->invalidator->markDrifted(
                emissionId: $event->emissionId,
                reason: $event->reason(),
                onlyEndedCompetences: true,
                causerId: $event->causerId,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
