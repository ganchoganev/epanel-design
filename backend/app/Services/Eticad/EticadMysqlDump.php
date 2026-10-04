<?php

namespace App\Services\Eticad;

use PDO;

/** Writes the portable ETICAD catalog as MySQL statements, one statement per line. */
class EticadMysqlDump
{
    public function write(PDO $pdo, string $target): void
    {
        $out = fopen($target, 'wb');
        if ($out === false) {
            return;
        }
        fwrite($out, "SET NAMES utf8mb4;\n");
        fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($this->tables() as $name => $ddl) {
            fwrite($out, 'DROP TABLE IF EXISTS `'.$name."`;\n");
            fwrite($out, $ddl.";\n");
            $this->insertAll($pdo, $out, $name);
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($out);
    }

    /** @return array<string, string> */
    private function tables(): array
    {
        return [
            'meta' => 'CREATE TABLE `meta` (`key` varchar(191) NOT NULL, `value` longtext NULL, PRIMARY KEY (`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'assets' => 'CREATE TABLE `assets` (`id` bigint unsigned NOT NULL, `container` text NULL, `member` text NOT NULL, `kind` varchar(32) NOT NULL, `offset` bigint NOT NULL, `size` bigint NOT NULL, `codec` varchar(32) NULL, `library` varchar(255) NULL, `block` varchar(255) NULL, `storage_path` varchar(512) NULL, PRIMARY KEY (`id`), KEY `idx_assets_block` (`library`, `block`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'dictionary' => 'CREATE TABLE `dictionary` (`key` varchar(191) NOT NULL, `value_en` longtext NULL, `value_bg` longtext NULL, `data` longtext NOT NULL, PRIMARY KEY (`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'products' => 'CREATE TABLE `products` (`id` bigint unsigned NOT NULL, `order_no` varchar(32) NULL, `name` text NULL, `full_name` text NULL, `article` text NULL, `article_1` text NULL, `article_2` text NULL, `voltage` text NULL, `ip` varchar(64) NULL, `power_losses` text NULL, `block_name` text NULL, `drawing_library` varchar(255) NULL, `drawing_block` varchar(255) NULL, `foto` text NULL, `photo_path` varchar(512) NULL, `pins` text NULL, `modular` text NULL, `max_current` text NULL, `din_modules` text NULL, `data` longtext NOT NULL, PRIMARY KEY (`id`), KEY `idx_products_order` (`order_no`), KEY `idx_products_drawing` (`drawing_library`, `drawing_block`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'categories' => 'CREATE TABLE `categories` (`id` bigint unsigned NOT NULL, `name_en` text NULL, `name_bg` text NULL, `dict_key` varchar(191) NULL, `path_en` text NULL, `path_key` text NULL, `block_name` text NULL, `dia` text NULL, `main_utx_key` text NULL, `data` longtext NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'records' => 'CREATE TABLE `records` (`id` bigint unsigned NOT NULL, `dataset` varchar(191) NOT NULL, `row_index` int NOT NULL, `data` longtext NOT NULL, PRIMARY KEY (`id`), KEY `idx_records_dataset` (`dataset`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'code_replacements' => 'CREATE TABLE `code_replacements` (`from_code` varchar(32) NOT NULL, `to_code` varchar(32) NOT NULL, PRIMARY KEY (`from_code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }

    /** @param  resource  $out */
    private function insertAll(PDO $pdo, $out, string $table): void
    {
        $stmt = $pdo->query('SELECT * FROM '.$table);
        if ($stmt === false) {
            return;
        }
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = (string) ($meta['name'] ?? '');
        }
        $columnSql = implode(', ', array_map(fn (string $column) => '`'.$column.'`', $columns));
        $batch = [];
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $batch[] = '('.implode(', ', array_map(fn (mixed $value) => $this->literal($value), $row)).')';
            if (count($batch) === 40) {
                $this->writeInsert($out, $table, $columnSql, $batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->writeInsert($out, $table, $columnSql, $batch);
        }
    }

    /** @param  resource  $out
     * @param  list<string>  $batch
     */
    private function writeInsert($out, string $table, string $columnSql, array $batch): void
    {
        fwrite($out, 'INSERT INTO `'.$table.'` ('.$columnSql.') VALUES '.implode(',', $batch).";\n");
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        $text = (string) $value;

        return "'".str_replace(
            ["\\", "'", "\0", "\n", "\r"],
            ["\\\\", "\\'", '\\0', '\\n', '\\r'],
            $text
        )."'";
    }
}
