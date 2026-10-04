<?php

namespace App\Console\Commands;

use App\Services\Eticad\EticadImporter;
use Illuminate\Console\Command;

class ImportEticadCommand extends Command
{
    protected $signature = 'eticad:import {--source=C:\\ProgramData\\CADprofi\\Producers\\EtiPolam}';

    protected $description = 'Импортира каталога, преводите и 2D индекса от инсталирания ETICAD';

    public function handle(EticadImporter $importer): int
    {
        $summary = $importer->import(
            $this->option('source'),
            database_path('eticad.sqlite'),
            storage_path('app/eticad/photos')
        );

        $this->info('База: '.database_path('eticad.sqlite'));
        $this->line('Изделия: '.$summary['products']);
        $this->line('Различни кодове: '.$summary['order_numbers']);
        $this->line('Категории: '.$summary['categories']);
        $this->line('Преводи: '.$summary['dictionary']);
        $this->line('2D блокове: '.$summary['blocks']);
        $this->line('Снимки: '.$summary['photos']);
        $this->line('Файлове в пакета: '.$summary['copied_files']);
        $this->line('Изделия с намерен 2D блок: '.$summary['drawings_linked']);
        $this->line('MySQL файл: '.$summary['sql']);
        if (is_array($summary['sample'])) {
            $this->line('Пример 001900030: '.json_encode($summary['sample'], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
