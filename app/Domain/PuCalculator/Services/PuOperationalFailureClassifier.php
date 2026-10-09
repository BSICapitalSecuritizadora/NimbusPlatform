<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use App\Domain\PuCalculator\Exceptions\BcbSgsException;
use App\Domain\PuCalculator\Exceptions\IndexRateObservationRefusedException;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Domain\PuCalculator\Exceptions\PuIndexerCapabilityException;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Domain\PuCalculator\Exceptions\PuNonOperationalProfileException;
use App\Domain\PuCalculator\Exceptions\PuNumericConvergenceException;
use App\Domain\PuCalculator\Exceptions\PuRateDomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PDOException;
use Throwable;

/**
 * Classificação central das falhas operacionais do PU (Fase 6): a mesma régua
 * decide se a atualização das obrigações repete sozinha, com que categoria a
 * extensão oficial registra a falha e o que a sincronização de índice guarda.
 *
 * Repetir só o que é passageiro (banco, trava, corrida, interrupção, fonte fora
 * do ar). Regra de domínio, governança, insumo inválido, efeito sem regra e
 * indexador sem homologação ficam bloqueados: repetir não muda a resposta e só
 * esconderia o problema num laço.
 *
 * Também higieniza a mensagem guardada: nada de credencial, token ou cabeçalho
 * de autenticação nos registros operacionais, e tamanho limitado.
 */
final class PuOperationalFailureClassifier
{
    /** Códigos MySQL passageiros: deadlock, espera de trava, conexão perdida. */
    private const TRANSIENT_MYSQL_CODES = [1205, 1213, 2002, 2006, 2013];

    private const MAX_MESSAGE_LENGTH = 1000;

    public function classify(Throwable $exception): PuOperationalFailureCategory
    {
        return match (true) {
            $exception instanceof PuIndexerCapabilityException,
            $exception instanceof PuNonOperationalProfileException => PuOperationalFailureCategory::Unsupported,
            $exception instanceof PuNumericConvergenceException,
            $exception instanceof PuRateDomainException => PuOperationalFailureCategory::Numerical,
            $exception instanceof PuMakerCheckerException,
            $exception instanceof PuCurveGovernanceException => PuOperationalFailureCategory::Governance,
            $exception instanceof PuCurveInputsException,
            $exception instanceof IndexRateObservationRefusedException => PuOperationalFailureCategory::InvalidInputs,
            $exception instanceof BcbSgsException => $exception->category ?? PuOperationalFailureCategory::ProviderUnavailable,
            $exception instanceof ConnectionException => PuOperationalFailureCategory::ProviderUnavailable,
            $exception instanceof LockTimeoutException => PuOperationalFailureCategory::TransientLock,
            $exception instanceof DeadlockException,
            $exception instanceof LostConnectionException => PuOperationalFailureCategory::TransientDatabase,
            $exception instanceof UniqueConstraintViolationException => PuOperationalFailureCategory::TransientConcurrency,
            $exception instanceof QueryException,
            $exception instanceof PDOException => $this->classifyDatabase($exception),
            $exception instanceof LogicException => PuOperationalFailureCategory::Invariant,
            $exception instanceof InvalidArgumentException => PuOperationalFailureCategory::InvalidInputs,
            default => PuOperationalFailureCategory::Unknown,
        };
    }

    /**
     * Mensagem segura para guardar: sem credenciais, numa linha, limitada.
     */
    public function sanitize(string $message): string
    {
        $redacted = (string) preg_replace('/(authorization\s*[:=]\s*)(?:bearer\s+|basic\s+)?[^\s,;&]+/i', '$1[redacted]', $message);
        $redacted = (string) preg_replace('/(bearer\s+)[^\s,;&]+/i', '$1[redacted]', $redacted);
        $redacted = (string) preg_replace(
            '/(password|passwd|pwd|secret|token|api[_-]?key)(["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
            '$1$2[redacted]',
            $redacted,
        );
        $redacted = (string) preg_replace('#(https?://)[^/\s:@]+:[^/\s@]+@#i', '$1[redacted]@', $redacted);

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $redacted)), self::MAX_MESSAGE_LENGTH);
    }

    private function classifyDatabase(Throwable $exception): PuOperationalFailureCategory
    {
        $driverCode = $exception instanceof QueryException || $exception instanceof PDOException
            ? (int) ($exception->errorInfo[1] ?? 0)
            : 0;
        $message = mb_strtolower($exception->getMessage());

        if (in_array($driverCode, self::TRANSIENT_MYSQL_CODES, true)
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'database is locked')
            || str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')) {
            return PuOperationalFailureCategory::TransientDatabase;
        }

        return PuOperationalFailureCategory::Unknown;
    }
}
