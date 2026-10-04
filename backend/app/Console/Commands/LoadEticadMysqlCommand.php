<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class LoadEticadMysqlCommand extends Command
{
    protected $signature = 'eticad:load-mysql';

    protected $description = 'Зарежда eticad.sql в MySQL, ако каталогът още е празен';

    public function handle(): int
    {
        if (config('database.connections.eticad.driver') !== 'mysql') {
            $this->line('Каталогът на ETICAD е на SQLite. Зареждане към MySQL се пропуска.');

            return self::SUCCESS;
        }

        $path = storage_path('app/eticad/eticad.sql');
        if (! is_file($path)) {
            $this->warn('Няма '. $path);

            return self::SUCCESS;
        }

        try {
            $loaded = DB::connection('eticad')->table('products')->count() > 0;
        } catch (Throwable) {
            $loaded = false;
        }
        if ($loaded) {
            $this->line('Каталогът на ETICAD вече е зареден.');

            return self::SUCCESS;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error('Файлът с каталога не се отваря.');

            return self::FAILURE;
        }

        $pdo = DB::connection('eticad')->getPdo();
        $count = 0;
        while (($line = fgets($handle)) !== false) {
            $statement = trim($line);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            $pdo->exec($statement);
            $count++;
        }
        fclose($handle);
        $this->info('Заредени са '.$count.' заявки от каталога на ETICAD.');

        return self::SUCCESS;
    }
}
