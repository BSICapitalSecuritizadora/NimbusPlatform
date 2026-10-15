<?php

namespace App\Support\ActivityLog;

use App\Console\Commands\CleanFilteredActivityLogCommand;
use App\Exceptions\ActivityLogCleanupRefusedException;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * A limpeza genérica do pacote, desligada.
 *
 * `activitylog:clean` apaga por idade -- numa trilha ou em todas -- sem saber
 * o que é evidência protegida: `--force --days=400` levaria a decisão sem
 * desembolso da Medição, a homologação do PU e o resto da classificação
 * protegida de `config/audit.php` aos 400 dias, e não ao fim da retenção
 * protegida. A limpeza da aplicação é uma só,
 * {@see CleanFilteredActivityLogCommand}, que confere os prazos e a
 * classificação antes de apagar.
 *
 * Registrada em `config/activitylog.php` como a ação `clean_log` do pacote,
 * que é por onde o comando apaga: trocar a ação, e não o comando, recusa
 * qualquer caminho que a chame, com ou sem `config:cache`, antes da primeira
 * consulta ao banco. A exclusão em si também recusa, para uma versão nova do
 * pacote que chegue a ela por outro método.
 */
class RefuseGenericActivityLogCleanup extends CleanActivityLogAction
{
    /**
     * @throws ActivityLogCleanupRefusedException
     */
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        throw new ActivityLogCleanupRefusedException($maxAgeInDays, $logName);
    }

    /**
     * @throws ActivityLogCleanupRefusedException
     */
    protected function deleteOldActivities(string $cutOffDate, ?string $logName): int
    {
        throw new ActivityLogCleanupRefusedException(null, $logName);
    }
}
