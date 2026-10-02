<?php

namespace App\Jobs;

use App\Support\Operations\ProcessHeartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * O sinal de vida da fila: grava a hora em que um worker o executou.
 *
 * O agendador o despacha a cada cinco minutos, e só um worker vivo o executa --
 * é isso que distingue "a fila está andando" de "os avisos estão parados na
 * fila". Único enquanto espera: com o worker parado, um job por despacho
 * empilharia centenas de cópias iguais; com `uniqueFor`, o lock expira sozinho
 * e não segura o sinal depois que o worker volta. Uma tentativa só, porque a
 * batida seguinte já é a nova tentativa.
 */
class RecordQueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function handle(): void
    {
        ProcessHeartbeat::recordQueue();
    }
}
