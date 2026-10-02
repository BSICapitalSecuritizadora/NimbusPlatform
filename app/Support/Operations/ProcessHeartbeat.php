<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Jobs\RecordQueueHeartbeat;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * O sinal de vida dos processos de fundo: o agendador e a fila.
 *
 * Sem ele, nada provava que o agendador estava rodando. Com o interruptor da
 * automação desligado nenhuma execução é registrada, e o aviso de execução
 * interrompida depende de uma execução seguinte acontecer -- um agendador morto
 * não gera execução nem aviso. O sinal é independente do interruptor por isso.
 *
 * Duas batidas, porque são dois processos:
 *
 * - o **agendador** grava a cada minuto (`scheduler-heartbeat`);
 * - a **fila** grava quando um worker executa o {@see RecordQueueHeartbeat},
 *   que o agendador despacha a cada cinco minutos. Os avisos da automação saem
 *   pela fila, e "enviado" na execução quer dizer enfileirado: sem worker, eles
 *   ficam retidos sem que nada acuse.
 *
 * Mora no cache, que em produção é o Redis compartilhado entre web, agendador e
 * worker: duas leituras por renderização, nenhuma escrita por minuto no banco.
 * Em ambiente com cache por processo (`array`), a tela mostraria "sem sinal"
 * por engano -- o sinal só faz sentido com cache compartilhado.
 *
 * O `/healthcheck` não muda: ele responde ao monitoramento externo, e um 503 por
 * agendador parado tiraria o site do ar por um problema que não é do site.
 */
final class ProcessHeartbeat
{
    public const SCHEDULER_KEY = 'operations:heartbeat:scheduler';

    public const QUEUE_KEY = 'operations:heartbeat:queue';

    /**
     * O agendador bate a cada minuto: cinco minutos sem sinal já é parada.
     */
    public const SCHEDULER_STALE_AFTER_MINUTES = 5;

    /**
     * A fila bate a cada cinco minutos, e o job pode esperar na fila atrás de
     * outros: quinze minutos sem sinal é worker parado.
     */
    public const QUEUE_STALE_AFTER_MINUTES = 15;

    public static function recordScheduler(): void
    {
        Cache::forever(self::SCHEDULER_KEY, CarbonImmutable::now()->toIso8601String());
    }

    public static function recordQueue(): void
    {
        Cache::forever(self::QUEUE_KEY, CarbonImmutable::now()->toIso8601String());
    }

    /**
     * O último sinal do agendador; `null` sem sinal registrado ou com o cache
     * indisponível.
     */
    public static function schedulerLastSeen(): ?CarbonImmutable
    {
        return self::read(self::SCHEDULER_KEY)['at'];
    }

    /**
     * O último sinal da fila; `null` sem sinal registrado ou com o cache
     * indisponível.
     */
    public static function queueLastSeen(): ?CarbonImmutable
    {
        return self::read(self::QUEUE_KEY)['at'];
    }

    /**
     * Uma linha por processo, como a tela as mostra.
     *
     * `healthy` é `true` com sinal recente, `false` com sinal vencido e `null`
     * quando não há como dizer -- sinal nunca registrado, cache indisponível, ou
     * a fila com o agendador parado: quem despacha o job da fila é o agendador,
     * e sem ele a falta de sinal da fila não diz nada sobre o worker.
     *
     * @return list<array{text: string, healthy: bool|null}>
     */
    public static function describe(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $scheduler = self::read(self::SCHEDULER_KEY);
        $schedulerLine = self::describeScheduler($scheduler, $now);

        return [
            $schedulerLine,
            $schedulerLine['healthy'] === true
                ? self::describeQueue(self::read(self::QUEUE_KEY), $now)
                : [
                    'text' => 'Fila: sem medição enquanto o agendador não estiver rodando — o sinal da fila é disparado por ele.',
                    'healthy' => null,
                ],
        ];
    }

    /**
     * @param  array{state: string, at: CarbonImmutable|null}  $reading
     * @return array{text: string, healthy: bool|null}
     */
    private static function describeScheduler(array $reading, CarbonImmutable $now): array
    {
        return match (true) {
            $reading['state'] === 'unavailable' => [
                'text' => 'Agendador: sinal indisponível — não foi possível ler o cache agora.',
                'healthy' => null,
            ],
            $reading['at'] === null => [
                'text' => 'Agendador: nenhum sinal de vida registrado ainda.',
                'healthy' => null,
            ],
            self::minutesSince($reading['at'], $now) > self::SCHEDULER_STALE_AFTER_MINUTES => [
                'text' => sprintf(
                    'Sem sinal do agendador desde %s (%s): nenhuma tarefa agendada está rodando — nem a automação, nem os lembretes. '
                        .'Verifique o processo schedule:work do App Service.',
                    self::moment($reading['at'], $now),
                    self::elapsed($reading['at'], $now),
                ),
                'healthy' => false,
            ],
            default => [
                'text' => sprintf('Agendador: último sinal às %s (%s).', self::moment($reading['at'], $now), self::elapsed($reading['at'], $now)),
                'healthy' => true,
            ],
        };
    }

    /**
     * @param  array{state: string, at: CarbonImmutable|null}  $reading
     * @return array{text: string, healthy: bool|null}
     */
    private static function describeQueue(array $reading, CarbonImmutable $now): array
    {
        return match (true) {
            $reading['state'] === 'unavailable' => [
                'text' => 'Fila: sinal indisponível — não foi possível ler o cache agora.',
                'healthy' => null,
            ],
            $reading['at'] === null => [
                'text' => 'Fila: nenhum sinal de vida registrado ainda.',
                'healthy' => null,
            ],
            self::minutesSince($reading['at'], $now) > self::QUEUE_STALE_AFTER_MINUTES => [
                'text' => sprintf(
                    'Sem sinal da fila desde %s (%s): avisos por e-mail e no sino ficam retidos até o processo queue:work voltar.',
                    self::moment($reading['at'], $now),
                    self::elapsed($reading['at'], $now),
                ),
                'healthy' => false,
            ],
            default => [
                'text' => sprintf('Fila: último sinal às %s (%s).', self::moment($reading['at'], $now), self::elapsed($reading['at'], $now)),
                'healthy' => true,
            ],
        };
    }

    /**
     * Leitura tolerante: cache fora do ar vira "indisponível", e não uma tela
     * quebrada.
     *
     * @return array{state: string, at: CarbonImmutable|null}
     */
    private static function read(string $key): array
    {
        try {
            $value = Cache::get($key);
        } catch (Throwable $exception) {
            report($exception);

            return ['state' => 'unavailable', 'at' => null];
        }

        if (! is_string($value) || $value === '') {
            return ['state' => 'missing', 'at' => null];
        }

        try {
            return ['state' => 'seen', 'at' => CarbonImmutable::parse($value)];
        } catch (Throwable) {
            return ['state' => 'missing', 'at' => null];
        }
    }

    private static function minutesSince(CarbonImmutable $at, CarbonImmutable $now): int
    {
        return (int) floor(max(0, $now->getTimestamp() - $at->getTimestamp()) / 60);
    }

    /**
     * A hora no fuso de negócio; com a data, quando não é o mesmo dia.
     */
    private static function moment(CarbonImmutable $at, CarbonImmutable $now): string
    {
        $local = BusinessTime::at($at);

        return $local->toDateString() === BusinessTime::at($now)->toDateString()
            ? $local->format('H:i')
            : $local->format('d/m/Y H:i');
    }

    private static function elapsed(CarbonImmutable $at, CarbonImmutable $now): string
    {
        $minutes = self::minutesSince($at, $now);

        return match (true) {
            $minutes < 120 => sprintf('há %d min', $minutes),
            $minutes < 48 * 60 => sprintf('há %d h', intdiv($minutes, 60)),
            default => sprintf('há %d dias', intdiv($minutes, 24 * 60)),
        };
    }
}
