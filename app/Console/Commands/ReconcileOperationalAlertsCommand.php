<?php

namespace App\Console\Commands;

use App\Actions\Alerts\ReconcileOperationalAlerts;
use Illuminate\Console\Command;

/**
 * Rebuilds the active operational alert set from source truth. Safe to run at any time, by hand or
 * on the scheduler: it reads business records and writes only alert tables.
 */
class ReconcileOperationalAlertsCommand extends Command
{
    protected $signature = 'inventra:reconcile-operational-alerts';

    protected $description = 'Re-derive operational alerts from current inventory and sales state';

    public function handle(ReconcileOperationalAlerts $reconcile): int
    {
        $result = $reconcile->execute();

        // Counts only. Product names, customer names and amounts stay out of scheduler output and
        // shared-hosting cron mail.
        $this->line('Subjects evaluated: '.$result['evaluated']);
        $this->line('Alerts resolved: '.$result['resolved']);
        $this->line('Subjects failed: '.$result['failed']);

        if ($result['failed'] > 0) {
            $this->warn('Some subjects could not be evaluated; details are in the application log.');

            return self::FAILURE;
        }

        $this->info('Operational alerts reconciled.');

        return self::SUCCESS;
    }
}
