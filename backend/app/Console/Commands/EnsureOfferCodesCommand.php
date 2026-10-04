<?php

namespace App\Console\Commands;

use Database\Seeders\EtiCatalogSeeder;
use Illuminate\Console\Command;

class EnsureOfferCodesCommand extends Command
{
    protected $signature = 'catalog:ensure-offer-codes';

    protected $description = 'Добавя кодовете ETIMAT P6 и EB2, с които PDF схемата става оферта';

    public function handle(EtiCatalogSeeder $seeder): int
    {
        $seeder->ensureOfferApparatus();
        $this->info('Кодовете за оферта от PDF са налични.');

        return self::SUCCESS;
    }
}
