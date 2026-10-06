<?php

namespace Tests\Feature;

use App\Models\EtiProduct;
use App\Services\EticadCatalogSync;
use App\Services\PriceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogSyncAndPriceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_eticad_rows_fill_the_offer_catalog_and_keep_a_price_list_name(): void
    {
        Schema::connection('eticad')->create('products', function ($table): void {
            $table->id();
            $table->string('order_no')->nullable();
            $table->string('name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('article')->nullable();
            $table->string('article_1')->nullable();
            $table->string('article_2')->nullable();
            $table->string('max_current')->nullable();
            $table->string('din_modules')->nullable();
            $table->string('modular')->nullable();
        });
        DB::connection('eticad')->table('products')->insert([
            'order_no' => '001900030',
            'name' => 'ETIMAT P6 1p C16',
            'full_name' => 'Miniature circuit breaker',
            'article' => 'ETIMAT P6',
            'article_1' => '1p C16',
            'article_2' => '6kA',
            'max_current' => '16',
            'din_modules' => '1',
            'modular' => '1',
        ]);
        EtiProduct::query()->create([
            'catalog_number' => '001900030',
            'name' => 'Миниатюрен автоматичен прекъсвач ETIMAT P6 1p C16',
            'price' => 3.57,
            'currency' => 'EUR',
            'data_source' => 'price_import',
            'width_modules' => 1,
        ]);

        $result = app(EticadCatalogSync::class)->sync();

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['updated']);
        $product = EtiProduct::query()->where('catalog_number', '001900030')->first();
        $this->assertSame('Миниатюрен автоматичен прекъсвач ETIMAT P6 1p C16', $product->name);
        $this->assertSame('3.5700', $product->price);
        $this->assertSame('ETIMAT P6', $product->series);
        $this->assertSame('C', $product->trip_curve);
        $this->assertSame('price_import', $product->data_source);
    }

    public function test_a_catalog_file_restores_the_row_the_offer_reads(): void
    {
        \App\Models\EtiProduct::query()->create([
            'catalog_number' => '001900030',
            'name' => 'ETIMAT P6 1p C16',
            'series' => 'ETIMAT P6',
            'category' => 'MCB',
            'poles' => 1,
            'rated_current_a' => 16,
            'trip_curve' => 'C',
            'breaking_capacity_ka' => 6,
            'price' => 3.57,
            'currency' => 'EUR',
            'data_source' => 'eticad',
            'width_modules' => 1,
            'verified' => true,
        ]);
        $path = app(\App\Services\CatalogFile::class)->export();
        \App\Models\EtiProduct::query()->delete();

        $result = app(\App\Services\CatalogFile::class)->import(
            new UploadedFile($path, 'katalog.csv', null, null, true)
        );

        $this->assertSame(1, $result['imported']);
        $product = \App\Models\EtiProduct::query()->where('catalog_number', '001900030')->first();
        $this->assertSame('MCB', $product->category);
        $this->assertSame('C', $product->trip_curve);
        $this->assertSame('3.5700', $product->price);
    }

    public function test_the_2026_gross_price_sheet_sets_euro_price_and_bulgarian_name(): void
    {
        $book = new Spreadsheet;
        $old = $book->getActiveSheet();
        $old->setTitle('Ценова оферта 2022');
        $old->fromArray(['Код', 'Описание', 'Цена'], null, 'A1');
        $old->fromArray(['001900002', 'Старо име', '1,00'], null, 'A2');
        $sheet = $book->createSheet();
        $sheet->setTitle('Ценова листа 2026');
        $sheet->fromArray(['Код', 'Описание', 'Модел', 'БРУТО 2026 EUR'], null, 'A1');
        $sheet->fromArray(['001900002', 'Миниатюрен автоматичен прекъсвач ETIMAT P6 1p D1', 'ETIMAT P6 1p D1', '7,63 €'], null, 'A2');
        $path = storage_path('framework/testing/price-list.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        (new Xlsx($book))->save($path);

        $file = new UploadedFile($path, 'Ценова листа ETI.xlsx', null, null, true);
        $preview = app(PriceImportService::class)->preview($file);
        $this->assertSame(0, $preview['suggested_mapping']['catalog_number']);
        $this->assertSame(1, $preview['suggested_mapping']['name']);
        $this->assertSame(3, $preview['suggested_mapping']['price']);

        $result = app(PriceImportService::class)->import($file, $preview['suggested_mapping']);

        $this->assertSame(1, $result['created']);
        $product = EtiProduct::query()->where('catalog_number', '001900002')->first();
        $this->assertSame('Миниатюрен автоматичен прекъсвач ETIMAT P6 1p D1', $product->name);
        $this->assertSame('7.6300', $product->price);
        $this->assertSame('EUR', $product->currency);
    }
}
