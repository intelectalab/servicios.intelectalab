<?php

namespace App\Console\Commands;

use App\Services\Webhooks;
use Illuminate\Console\Command;

class DeliverWebhooks extends Command
{
    protected $signature = 'webhooks:deliver';

    protected $description = 'Envía los webhooks pendientes y los reintentos que ya toca reintentar, en cada empresa';

    public function handle(): int
    {
        $count = Webhooks::deliverDue();
        if ($count) {
            $this->line("{$count} envíos intentados");
        }

        return self::SUCCESS;
    }
}
