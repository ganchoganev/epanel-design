<?php

namespace Tests\Unit;

use App\Services\Eticad\EticadEnclosureCatalog;
use PHPUnit\Framework\TestCase;

class EticadEnclosureCatalogTest extends TestCase
{
    public function test_boards_have_unique_codes_and_a_real_capacity(): void
    {
        $boards = (new EticadEnclosureCatalog)->all();
        $codes = array_column($boards, 'catalog_number');

        $this->assertCount(count($codes), array_unique($codes));
        $this->assertContains('001101001', $codes);
        foreach ($boards as $board) {
            $this->assertSame($board['rows'] * $board['modules_per_row'] > 0, true);
            $this->assertContains($board['mounting'], ['вграден', 'открит']);
        }
    }
}
