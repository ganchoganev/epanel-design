<?php

namespace App\Services\Eticad;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Reads the installed CADprofi ETICAD library and copies every catalog table,
 * drawing index and product photo into the dedicated eticad database.
 */
class EticadImporter
{
    /** @var array<string, array{en: ?string, bg: ?string}> */
    private array $dictionary = [];

    /** @var array<string, string> */
    private array $photos = [];

    public function import(string $source, string $database, string $photoDirectory): array
    {
        if (! is_dir($source)) {
            throw new RuntimeException('ETICAD библиотеката не е намерена: '.$source);
        }

        $package = dirname($photoDirectory);
        $keptReplacements = $this->existingReplacements($database);
        foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (['photos', 'blocks', 'slides', 'tables', 'files'] as $folder) {
            $path = $package.DIRECTORY_SEPARATOR.$folder;
            if (is_dir($path)) {
                $this->deleteTree($path);
            }
            mkdir($path, 0777, true);
        }

        $pdo = new PDO('sqlite:'.$database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = OFF');
        $this->schema($pdo);

        $version = $this->libraryVersion($source);
        $this->meta($pdo, 'source', 'eticad-package');
        $this->meta($pdo, 'imported_at', date('c'));
        $this->meta($pdo, 'library_version', $version);
        $this->restoreReplacements($pdo, $keptReplacements);

        $archives = $this->archives($source);
        $tables = [];
        $asset = $pdo->prepare(
            'INSERT INTO assets (container, member, kind, offset, size, codec, library, block, storage_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $pdo->beginTransaction();
        foreach ($archives as $path) {
            foreach ($this->entries($path) as $entry) {
                $kind = $this->kind($path, $entry['name']);
                $codec = null;
                $library = $this->libraryName($path);
                $block = $this->blockName($entry['name']);
                if ($kind === 'block') {
                    $codec = $this->codec($path, $entry);
                }
                $storagePath = null;
                if ($kind === 'photo') {
                    $this->extractPhoto($path, $entry, $photoDirectory);
                    if (preg_match('/(\d{6,})_photo\.jpg$/i', $entry['name'], $match)) {
                        $storagePath = 'eticad/photos/'.$match[1].'.jpg';
                    }
                } else {
                    $storagePath = $this->extractPackageFile($path, $entry, $package, $kind, $library, $block);
                }
                $asset->execute([
                    '',
                    $entry['name'],
                    $kind,
                    $entry['offset'],
                    $entry['size'],
                    $codec,
                    $library,
                    $block,
                    $storagePath,
                ]);
                if ($kind === 'table') {
                    $tables[] = [$path, $entry['name']];
                }
            }
        }
        $pdo->commit();

        usort($tables, function (array $left, array $right): int {
            return $this->tableOrder($left[1]) <=> $this->tableOrder($right[1]);
        });
        $counts = [];
        foreach ($tables as [$path, $member]) {
            $parsed = $this->parseUtx($this->readMember($path, $member));
            if ($parsed === null) {
                $counts[$member] = 0;
                continue;
            }
            $counts[$member] = $this->storeTable($pdo, $member, $parsed['columns'], $parsed['rows']);
        }

        $this->meta($pdo, 'datasets', json_encode($counts, JSON_UNESCAPED_UNICODE));
        $sqlPath = $package.DIRECTORY_SEPARATOR.'eticad.sql';
        (new EticadMysqlDump)->write($pdo, $sqlPath);

        return [
            'products' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
            'order_numbers' => (int) $pdo->query('SELECT COUNT(DISTINCT order_no) FROM products WHERE order_no IS NOT NULL')->fetchColumn(),
            'categories' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
            'dictionary' => (int) $pdo->query('SELECT COUNT(*) FROM dictionary')->fetchColumn(),
            'blocks' => (int) $pdo->query("SELECT COUNT(*) FROM assets WHERE kind = 'block'")->fetchColumn(),
            'photos' => count($this->photos),
            'drawings_linked' => (int) $pdo->query(
                "SELECT COUNT(*) FROM products p
                 WHERE p.drawing_block IS NOT NULL
                   AND EXISTS (
                     SELECT 1 FROM assets a
                     WHERE a.kind = 'block' AND a.library = p.drawing_library AND a.block = p.drawing_block
                   )"
            )->fetchColumn(),
            'sample' => $pdo->query("SELECT order_no, name, article, article_1, drawing_library, drawing_block, photo_path FROM products WHERE order_no = '001900030' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null,
            'datasets' => $counts,
            'sql' => $sqlPath,
            'copied_files' => (int) $pdo->query('SELECT COUNT(*) FROM assets WHERE storage_path IS NOT NULL')->fetchColumn(),
        ];
    }

    private function schema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE meta (
                key TEXT PRIMARY KEY,
                value TEXT
            );
            CREATE TABLE assets (
                id INTEGER PRIMARY KEY,
                container TEXT NOT NULL,
                member TEXT NOT NULL,
                kind TEXT NOT NULL,
                offset INTEGER NOT NULL,
                size INTEGER NOT NULL,
                codec TEXT,
                library TEXT,
                block TEXT,
                storage_path TEXT
            );
            CREATE INDEX idx_assets_block ON assets(library, block);
            CREATE TABLE dictionary (
                key TEXT PRIMARY KEY,
                value_en TEXT,
                value_bg TEXT,
                data TEXT NOT NULL
            );
            CREATE TABLE products (
                id INTEGER PRIMARY KEY,
                order_no TEXT,
                name TEXT,
                full_name TEXT,
                article TEXT,
                article_1 TEXT,
                article_2 TEXT,
                voltage TEXT,
                ip TEXT,
                power_losses TEXT,
                block_name TEXT,
                drawing_library TEXT,
                drawing_block TEXT,
                foto TEXT,
                photo_path TEXT,
                pins TEXT,
                modular TEXT,
                max_current TEXT,
                din_modules TEXT,
                data TEXT NOT NULL
            );
            CREATE INDEX idx_products_order ON products(order_no);
            CREATE INDEX idx_products_drawing ON products(drawing_library, drawing_block);
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY,
                name_en TEXT,
                name_bg TEXT,
                dict_key TEXT,
                path_en TEXT,
                path_key TEXT,
                block_name TEXT,
                dia TEXT,
                main_utx_key TEXT,
                data TEXT NOT NULL
            );
            CREATE TABLE records (
                id INTEGER PRIMARY KEY,
                dataset TEXT NOT NULL,
                row_index INTEGER NOT NULL,
                data TEXT NOT NULL
            );
            CREATE INDEX idx_records_dataset ON records(dataset);
            CREATE TABLE code_replacements (
                from_code TEXT PRIMARY KEY,
                to_code TEXT NOT NULL
            );
        SQL);
    }

    /**
     * @param  list<array{name: string, type: int, width: int}>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function storeTable(PDO $pdo, string $member, array $columns, array $rows): int
    {
        $upper = strtoupper($member);
        if ($upper === 'DICTETI.UTX') {
            return $this->storeDictionary($pdo, $rows);
        }
        if ($upper === 'ETI_ALL.UTX') {
            return $this->storeProducts($pdo, $rows);
        }
        if ($upper === 'ETIDATA.UTX') {
            return $this->storeCategories($pdo, $rows);
        }

        $insert = $pdo->prepare('INSERT INTO records (dataset, row_index, data) VALUES (?, ?, ?)');
        $pdo->beginTransaction();
        foreach ($rows as $index => $row) {
            $insert->execute([$member, $index, $this->json($row)]);
        }
        $pdo->commit();

        return count($rows);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function storeDictionary(PDO $pdo, array $rows): int
    {
        $insert = $pdo->prepare('INSERT OR REPLACE INTO dictionary (key, value_en, value_bg, data) VALUES (?, ?, ?, ?)');
        $pdo->beginTransaction();
        $count = 0;
        foreach ($rows as $row) {
            $key = $this->text($row['Key'] ?? null);
            if ($key === null) {
                continue;
            }
            $en = $this->text($row['VALUE'] ?? null);
            $bg = $this->text($row['ValueBg'] ?? null);
            $this->dictionary[$key] = ['en' => $en, 'bg' => $bg];
            $insert->execute([$key, $en, $bg, $this->json($row)]);
            $count++;
        }
        $pdo->commit();

        return $count;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function storeProducts(PDO $pdo, array $rows): int
    {
        $insert = $pdo->prepare(
            'INSERT INTO products (
                order_no, name, full_name, article, article_1, article_2, voltage, ip, power_losses,
                block_name, drawing_library, drawing_block, foto, photo_path, pins, modular, max_current, din_modules, data
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $pdo->beginTransaction();
        foreach ($rows as $row) {
            $orderNo = $this->text($row['Order-No'] ?? null);
            [$library, $block] = $this->drawingReference($this->text($row['Dia'] ?? null) ?? $this->text($row['SrcBlockName'] ?? null));
            $insert->execute([
                $orderNo,
                $this->text($row['Name'] ?? null),
                $this->label($this->text($row['FullName'] ?? null), 'en'),
                $this->text($row['Article'] ?? null),
                $this->text($row['Article-1'] ?? null),
                $this->text($row['Article-2'] ?? null),
                $this->text($row['Voltage'] ?? null),
                $this->text($row['IP'] ?? null),
                $this->text($row['POWER_LOSSES'] ?? null),
                $this->text($row['BlockName'] ?? null),
                $library,
                $block,
                $this->text($row['FOTO'] ?? null),
                $orderNo !== null ? ($this->photos[$orderNo] ?? null) : null,
                $this->text($row['PINS'] ?? null),
                $this->text($row['MODULAR_APPARATUS'] ?? null),
                $this->text($row['MAX_CURRENT'] ?? null),
                $this->text($row['DIN_MODULES'] ?? null),
                $this->json($row),
            ]);
        }
        $pdo->commit();

        return count($rows);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function storeCategories(PDO $pdo, array $rows): int
    {
        $insert = $pdo->prepare(
            'INSERT INTO categories (name_en, name_bg, dict_key, path_en, path_key, block_name, dia, main_utx_key, data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $pdo->beginTransaction();
        foreach ($rows as $row) {
            $name = $this->text($row['Name'] ?? null);
            $category = $this->text($row['Category'] ?? null);
            $insert->execute([
                $this->label($name, 'en'),
                $this->label($name, 'bg'),
                $this->dictKey($name),
                $this->label($category, 'en'),
                $this->dictKey($category),
                $this->text($row['BlockName'] ?? null),
                $this->text($row['DIA'] ?? null),
                $this->text($row['MainUtxKey'] ?? null),
                $this->json($row),
            ]);
        }
        $pdo->commit();

        return count($rows);
    }

    /** @return list<string> */
    private function archives(string $source): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['utb', 'dwb', 'slb', 'bmb'], true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<array{name: string, offset: int, size: int}> */
    private function entries(string $path): array
    {
        $fh = fopen($path, 'rb');
        $magic = fread($fh, 32);
        if (! str_starts_with($magic, 'CAD-PROFI') && ! str_starts_with($magic, 'AutoCAD Slide')) {
            fclose($fh);

            return [['name' => basename($path), 'offset' => 0, 'size' => filesize($path)]];
        }
        $entries = [];
        while (! feof($fh)) {
            $raw = fread($fh, 36);
            if (strlen($raw) < 36) {
                break;
            }
            $name = rtrim(substr($raw, 0, 32), "\0");
            $offset = unpack('V', substr($raw, 32, 4))[1];
            if ($name === '') {
                break;
            }
            $entries[] = ['name' => $name, 'offset' => $offset, 'size' => 0];
        }
        fclose($fh);
        $fileSize = filesize($path);
        foreach ($entries as $index => $entry) {
            $next = $entries[$index + 1]['offset'] ?? $fileSize;
            $entries[$index]['size'] = $next - $entry['offset'];
        }

        return $entries;
    }

    private function readMember(string $path, string $name): string
    {
        foreach ($this->entries($path) as $entry) {
            if (strcasecmp($entry['name'], $name) !== 0) {
                continue;
            }
            $fh = fopen($path, 'rb');
            fseek($fh, $entry['offset']);
            $data = fread($fh, $entry['size']);
            fclose($fh);

            return $data === false ? '' : $data;
        }

        return '';
    }

    /**
     * @return array{columns: list<array{name: string, type: int, width: int}>, rows: list<array<string, mixed>>}|null
     */
    private function parseUtx(string $blob): ?array
    {
        $title = strtolower(rtrim(substr($blob, 0, 32), "\0"));
        if (! str_ends_with($title, '.utx')) {
            return null;
        }
        $columns = [];
        $pos = 0x40;
        while ($pos + 36 <= strlen($blob)) {
            $raw = substr($blob, $pos, 36);
            $name = rtrim(substr($raw, 0, 32), "\0");
            $type = ord($raw[32]);
            $width = unpack('v', substr($raw, 34, 2))[1];
            $pos += 36;
            if ($name === '' && $width === 0) {
                break;
            }
            $columns[] = ['name' => $name, 'type' => $type, 'width' => $width];
        }
        $rowSize = array_sum(array_column($columns, 'width'));
        if ($rowSize < 1 || ($pos + $rowSize) > strlen($blob)) {
            return ['columns' => $columns, 'rows' => []];
        }
        $rows = [];
        while ($pos + $rowSize <= strlen($blob)) {
            $raw = substr($blob, $pos, $rowSize);
            $cursor = 0;
            $row = [];
            foreach ($columns as $column) {
                $field = substr($raw, $cursor, $column['width']);
                $cursor += $column['width'];
                $row[$column['name']] = $this->decodeField($field, $column['type']);
            }
            $rows[] = $row;
            $pos += $rowSize;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    private function decodeField(string $field, int $type): mixed
    {
        if ($type === 1 && strlen($field) === 4) {
            return unpack('V', $field)[1];
        }
        if ($type === 2 && strlen($field) === 8) {
            return round(unpack('e', $field)[1], 4);
        }
        $text = rtrim($field, "\0 ");
        if ($text === '') {
            return null;
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        return $text === '' ? null : $text;
    }

    /** @return list<array{0: string, 1: string}> */
    private function existingReplacements(string $database): array
    {
        if (! is_file($database)) {
            return [];
        }
        try {
            $pdo = new PDO('sqlite:'.$database);
            $rows = $pdo->query('SELECT from_code, to_code FROM code_replacements')->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /** @param  list<array{0: string, 1: string}>  $rows */
    private function restoreReplacements(PDO $pdo, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $insert = $pdo->prepare('INSERT INTO code_replacements (from_code, to_code) VALUES (?, ?)');
        foreach ($rows as $row) {
            $insert->execute([(string) $row[0], (string) $row[1]]);
        }
    }

    /**
     * Copies one archive member into the portable package and returns its path
     * relative to storage/app.
     *
     * @param  array{name: string, offset: int, size: int}  $entry
     */
    private function extractPackageFile(string $path, array $entry, string $package, string $kind, ?string $library, ?string $block): ?string
    {
        $bytes = $this->readSlice($path, $entry['offset'], $entry['size']);
        if ($bytes === null || $bytes === '') {
            return null;
        }
        $folder = match ($kind) {
            'block' => 'blocks',
            'slide' => 'slides',
            'table' => 'tables',
            default => 'files',
        };
        $libraryName = $this->safeName($library ?? 'misc');
        $fileName = $this->safeName($block ?? pathinfo($entry['name'], PATHINFO_FILENAME));
        $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
        if ($kind === 'block') {
            $extension = 'dxz';
        }
        if ($extension !== '') {
            $fileName .= '.'.$extension;
        }
        $relative = 'eticad/'.$folder.'/'.$libraryName.'/'.$fileName;
        $target = $package.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.$libraryName.DIRECTORY_SEPARATOR.$fileName;
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, $bytes);

        return $relative;
    }

    private function safeName(string $name): string
    {
        $name = str_replace(['\\', '/'], '-', $name);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? $name;

        return $clean !== '' ? $clean : 'item';
    }

    private function readSlice(string $path, int $offset, int $size): ?string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        fseek($handle, $offset);
        $bytes = fread($handle, $size);
        fclose($handle);

        return is_string($bytes) ? $bytes : null;
    }

    /** @param  array{offset: int, size: int}  $entry */
    private function extractPhoto(string $path, array $entry, string $photoDirectory): void
    {
        if (! preg_match('/(\d{6,})_photo\.jpg$/i', $entry['name'], $match)) {
            return;
        }
        $bytes = $this->readSlice($path, $entry['offset'], $entry['size']);
        if (! is_string($bytes) || ! str_starts_with($bytes, "\xFF\xD8")) {
            return;
        }
        $target = $photoDirectory.DIRECTORY_SEPARATOR.$match[1].'.jpg';
        file_put_contents($target, $bytes);
        $this->photos[$match[1]] = 'eticad/photos/'.$match[1].'.jpg';
    }

    /** @param  array{offset: int, size: int}  $entry */
    private function codec(string $path, array $entry): ?string
    {
        $fh = fopen($path, 'rb');
        fseek($fh, $entry['offset']);
        $magic = fread($fh, 2);
        fclose($fh);

        return $magic === "\x78\x9C" ? 'zlib-dxf' : null;
    }

    private function kind(string $path, string $member): string
    {
        $container = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $ext = strtolower(pathinfo($member, PATHINFO_EXTENSION));
        if ($container === 'bmb' && $ext === 'jpg') {
            return 'photo';
        }
        if ($container === 'dwb') {
            return 'block';
        }
        if ($container === 'slb') {
            return 'slide';
        }
        if ($ext === 'utx') {
            return 'table';
        }

        return 'other';
    }

    private function libraryName(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, ['dwb', 'slb'], true)) {
            return null;
        }

        return strtoupper(pathinfo($path, PATHINFO_FILENAME));
    }

    private function blockName(string $member): ?string
    {
        $name = pathinfo($member, PATHINFO_FILENAME);

        return $name === '' ? null : strtoupper($name);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function drawingReference(?string $reference): array
    {
        if ($reference !== null && preg_match('/^([^(]+)\(([^)]+)\)$/', $reference, $match)) {
            return [strtoupper($match[1]), strtoupper($match[2])];
        }

        return [null, null];
    }

    private function label(?string $text, string $language): ?string
    {
        $key = $this->dictKey($text);
        if ($text === null) {
            return null;
        }
        if ($key === null) {
            return $text;
        }
        $translated = $language === 'bg' ? ($this->dictionary[$key]['bg'] ?? null) : ($this->dictionary[$key]['en'] ?? null);
        if ($translated !== null && $translated !== '') {
            return $translated;
        }
        if (preg_match('/^\{@\s*(.*?)\s*\|@\|/s', $text, $match)) {
            return $match[1];
        }

        return $text;
    }

    private function dictKey(?string $text): ?string
    {
        if ($text !== null && preg_match('/\|@\|\s*(.*?)\s*@\}$/s', $text, $match)) {
            return $match[1];
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : (string) $value;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function json(array $row): string
    {
        return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function meta(PDO $pdo, string $key, ?string $value): void
    {
        $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?)')->execute([$key, $value]);
    }

    private function tableOrder(string $member): int
    {
        return match (strtoupper($member)) {
            'DICTETI.UTX' => 0,
            'ETI_ALL.UTX' => 1,
            'ETIDATA.UTX' => 2,
            default => 3,
        };
    }

    private function libraryVersion(string $source): ?string
    {
        $ini = $source.DIRECTORY_SEPARATOR.'cpconf.ini';
        if (! is_file($ini)) {
            return null;
        }
        $parsed = parse_ini_file($ini, true, INI_SCANNER_RAW);

        return is_array($parsed) ? ($parsed['FileVersion']['FileVersion'] ?? null) : null;
    }

    private function deleteTree(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
