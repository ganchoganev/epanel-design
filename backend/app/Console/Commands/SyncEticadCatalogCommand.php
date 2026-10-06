<?php

namespace App\Console\Commands;

use App\Services\EticadCatalogSync;
use Illuminate\Console\Command;

class SyncEticadCatalogCommand extends Command
{
    protected $signature = 'catalog:sync-eticad';

    protected $description = 'Само на Windows: копира ETICAD в каталога в базата. Офертата после чете базата и не отваря ETICAD.';

    public function handle(EticadCatalogSync $sync): int
    {
        $result = $sync->sync();
        $this->info('Нови: '.$result['imported'].'. Обновени: '.$result['updated'].'. Пропуснати: '.$result['skipped'].'.');

        return self::SUCCESS;
    }
}
