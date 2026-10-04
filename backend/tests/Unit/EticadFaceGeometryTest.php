<?php

namespace Tests\Unit;

use App\Services\Eticad\EticadFaceGeometry;
use PHPUnit\Framework\TestCase;

class EticadFaceGeometryTest extends TestCase
{
    public function test_front_view_keeps_the_body_and_drops_a_distant_leader(): void
    {
        $dxf = implode("\n", [
            '0', 'LINE', '10', '0', '20', '0', '11', '0', '21', '80',
            '0', 'LINE', '10', '18', '20', '0', '11', '18', '21', '80',
            '0', 'LINE', '10', '0', '20', '80', '11', '18', '21', '80',
            '0', 'CIRCLE', '10', '9', '20', '40', '40', '3',
            '0', 'LINE', '10', '200', '20', '-200', '11', '210', '21', '-200',
            '0', 'EOF',
        ]);

        $face = (new EticadFaceGeometry)->fromDxf($dxf);

        $this->assertNotNull($face);
        $this->assertSame(20.0, $face['width_mm']);
        $this->assertSame(82.0, $face['height_mm']);
        $this->assertStringContainsString('<circle', $face['svg']);
        $this->assertStringNotContainsString('210', $face['svg']);
        $this->assertSame([], $face['rails']);
    }

    public function test_enclosure_rail_is_the_module_pair_not_the_outer_frame(): void
    {
        $dxf = implode("\n", [
            '0', 'LINE', '10', '0', '20', '0', '11', '287', '21', '0',
            '0', 'LINE', '10', '0', '20', '236', '11', '287', '21', '236',
            '0', 'LINE', '10', '0', '20', '0', '11', '0', '21', '236',
            '0', 'LINE', '10', '287', '20', '0', '11', '287', '21', '236',
            '0', 'LINE', '10', '35.5', '20', '95.5', '11', '251.5', '21', '95.5',
            '0', 'LINE', '10', '35.5', '20', '140.5', '11', '251.5', '21', '140.5',
            '0', 'EOF',
        ]);

        $face = (new EticadFaceGeometry)->fromDxf($dxf);

        $this->assertNotNull($face);
        $this->assertCount(1, $face['rails']);
        $this->assertEqualsWithDelta(36.5, $face['rails'][0]['x'], 0.05);
        $this->assertEqualsWithDelta(119.0, $face['rails'][0]['y'], 0.05);
        $this->assertEqualsWithDelta(216.0, $face['rails'][0]['width'], 0.05);
    }
}
