<?php

namespace App\Console\Commands;

use App\Services\OrderExpirationService;
use Illuminate\Console\Command;

class ExpirePendingOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:expire-pending {--hours=24 : Cutoff hours for unpaid orders}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire unpaid pending orders whose payment window has timed out and restore inventory stock';

    /**
     * Execute the console command.
     */
    public function handle(OrderExpirationService $service): int
    {
        $hours = (int) $this->option('hours');
        $this->info("Scanning for pending orders older than {$hours} hours...");

        $expiredCount = $service->expirePendingOrders($hours);

        $this->info("Successfully expired {$expiredCount} pending order(s) and restored reserved inventory stock.");

        return Command::SUCCESS;
    }
}
