<?php

namespace App\Console\Commands;

use App\Services\Orders\OrderCompletionService;
use Illuminate\Console\Command;

class CompleteDeliveredOrdersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'orders:complete-delivered {--days= : Days after delivery before an unconfirmed order is completed}';

    /**
     * @var string
     */
    protected $description = 'Complete delivered orders the customer did not confirm within the configured number of days';

    public function handle(OrderCompletionService $service): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('orders.auto_complete_days')));
        $completed = $service->completeStaleDeliveredOrders($days);

        $this->info("Completed {$completed} delivered order(s) older than {$days} day(s).");

        return Command::SUCCESS;
    }
}
