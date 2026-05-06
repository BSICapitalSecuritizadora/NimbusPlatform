<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;

class PruneExpiredInvitations extends Command
{
    protected $signature = 'invitations:prune {--days=30 : Delete invitations expired more than this many days ago}';

    protected $description = 'Delete expired invitation tokens that have not been accepted';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $deleted = Invitation::query()
            ->whereNull('used_at')
            ->where('expires_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} expired invitation(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
