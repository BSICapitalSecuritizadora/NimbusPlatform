<?php

namespace App\Filament\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use PDOException;
use Throwable;

/**
 * Reconhece a recusa do banco por concorrência -- deadlock (1213, SQLSTATE
 * 40001) ou espera por lock estourada (1205) -- para a tela pedir uma nova
 * tentativa em vez de responder com erro de servidor.
 *
 * Registrar Pagamento e a Finalização seguram a Operation durante o antivírus
 * e os checksums; quem espera por ela (envio e edição de medição, a gravação da
 * operação, as transições do ciclo de vida) pode passar do
 * `innodb_lock_wait_timeout`, e um ciclo de locks termina em 1213. Nos dois
 * casos a transação foi desfeita e a ação pode ser repetida em instantes. Só a
 * visualização da Medição traduzia isso; o resto devolvia HTTP 500.
 *
 * Na transação de topo o banco entrega a `QueryException`; dentro de outra
 * transação o Laravel a embrulha numa `DeadlockException`. Por isso a busca
 * percorre `getPrevious()` até a `PDOException`. Quem decide é o detector do
 * próprio framework, pela mensagem e pelo SQLSTATE -- o mesmo que decide o
 * retry de `DB::transaction()`.
 *
 * O método é estático para servir também onde não há instância: as ações do
 * Resource e o closure de gravação de um campo de formulário. As mensagens
 * ficam aqui para a mesma situação ser descrita do mesmo jeito em todas as
 * telas que a tratam.
 */
trait DetectsConcurrentUpdates
{
    public const CONCURRENT_OPERATION_UPDATE_MESSAGE = 'Outra pessoa está atualizando esta operação. Tente novamente em instantes.';

    public const CONCURRENT_MEASUREMENT_UPDATE_MESSAGE = 'Outra pessoa está atualizando esta medição. Tente novamente em instantes.';

    protected static function isConcurrentUpdate(Throwable $exception): bool
    {
        $container = Container::getInstance();
        $detector = $container->bound(ConcurrencyErrorDetectorContract::class)
            ? $container->make(ConcurrencyErrorDetectorContract::class)
            : new ConcurrencyErrorDetector;

        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if (($current instanceof PDOException) && $detector->causedByConcurrencyError($current)) {
                return true;
            }
        }

        return false;
    }
}
