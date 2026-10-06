<?php

namespace Tests\Unit;

use App\Services\EticadCatalogSync;
use PHPUnit\Framework\TestCase;

class EticadCatalogSyncTest extends TestCase
{
    public function test_a_breaker_row_keeps_poles_curve_current_and_breaking_capacity(): void
    {
        $parsed = (new EticadCatalogSync)->attributes([
            'name' => 'ETIMAT P6 1p C16',
            'full_name' => 'Miniature circuit breaker',
            'article' => 'ETIMAT P6',
            'article_1' => '1p C16',
            'article_2' => '6kA',
            'max_current' => '16',
            'din_modules' => '1',
            'modular' => '1',
        ]);

        $this->assertSame('MCB', $parsed['category']);
        $this->assertSame('ETIMAT P6', $parsed['series']);
        $this->assertSame(1, $parsed['poles']);
        $this->assertSame('C', $parsed['trip_curve']);
        $this->assertSame(16.0, $parsed['rated_current_a']);
        $this->assertSame(6.0, $parsed['breaking_capacity_ka']);
    }

    public function test_an_rcbo_row_keeps_residual_current_and_type(): void
    {
        $parsed = (new EticadCatalogSync)->attributes([
            'name' => 'KZS-2M AC C16/0.03',
            'full_name' => 'RCBO',
            'article' => 'KZS-2M',
            'article_1' => 'C16/0,03',
            'article_2' => 'AC',
            'max_current' => '16',
            'din_modules' => '2',
            'modular' => '1',
        ]);

        $this->assertSame('RCBO', $parsed['category']);
        $this->assertSame(2, $parsed['poles']);
        $this->assertSame('C', $parsed['trip_curve']);
        $this->assertSame(0.03, $parsed['residual_current_a']);
        $this->assertSame('AC', $parsed['rcd_type']);
    }
}
