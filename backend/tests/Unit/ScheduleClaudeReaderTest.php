<?php

namespace Tests\Unit;

use App\Services\Claude\ClaudeClient;
use App\Services\Offers\AutocadScheduleParser;
use App\Services\Offers\ScheduleClaudeReader;
use App\Services\Offers\ScheduleTableReader;
use PHPUnit\Framework\TestCase;

class ScheduleClaudeReaderTest extends TestCase
{
    public function test_parse_keeps_every_device_and_every_unreadable_mark(): void
    {
        $parsed = $this->reader()->parse(<<<'JSON'
{"devices":[{"board":"ГЕТ","kind":"МАП","poles":1,"current":16,"curve":"C","quantity":2,"text":"C/16A/1"},{"board":"ГЕТ","kind":"ПЛК","poles":3,"current":160,"curve":null,"quantity":1,"text":"160A/3P/36kA"}],"unread":[{"board":"ГЕТ","text":"SV 40A","quantity":3,"reason":"товаров прекъсвач"}]}
JSON);

        $this->assertCount(3, $parsed['circuits']);
        $this->assertSame('16A/1P/C', $parsed['circuits'][0]->rating);
        $this->assertSame('МАП', $parsed['circuits'][0]->deviceType);
        $this->assertSame('160A/3P', $parsed['circuits'][2]->rating);
        $this->assertSame('ПЛК', $parsed['circuits'][2]->deviceType);
        $this->assertSame('SV 40A', $parsed['unread'][0]['text']);
        $this->assertSame(3, $parsed['unread'][0]['quantity']);
    }

    public function test_a_bad_claude_reply_still_keeps_every_table_row(): void
    {
        $table = [
            ['n' => 1, 'board' => 'ГЕТ', 'text' => 'C/63A/1', 'kind' => 'МАП', 'poles' => 1, 'current' => 63, 'curve' => 'C', 'role' => 'breaker'],
            ['n' => 2, 'board' => 'ГЕТ', 'text' => 'C16A/1+N', 'kind' => null, 'poles' => 1, 'current' => 16, 'curve' => 'C', 'role' => 'rcd'],
        ];
        $method = new \ReflectionMethod(ScheduleClaudeReader::class, 'fromTable');
        $parsed = $method->invoke($this->reader(), $table, 'не е json');

        $this->assertSame(2, $parsed['table_count']);
        $this->assertCount(2, $parsed['circuits']);
        $this->assertSame('C16A/1+N', $parsed['circuits'][1]->rating);
        $this->assertSame('ДТЗ', $parsed['circuits'][1]->deviceType);
        $this->assertSame([], $parsed['unread']);
    }

    public function test_a_residual_current_device_keeps_its_rating(): void
    {
        $parsed = $this->reader()->parse('{"devices":[{"board":"Т1","kind":"ДТЗ","poles":2,"current":40,"residual_ma":30,"quantity":1,"text":"ID=40A 30mA 2P"}],"unread":[]}');

        $this->assertCount(1, $parsed['circuits']);
        $this->assertSame('ДТЗ', $parsed['circuits'][0]->deviceType);
        $this->assertSame('40A/2P/30mA', $parsed['circuits'][0]->rating);
        $this->assertSame(40.0, $parsed['circuits'][0]->current);
    }

    public function test_a_row_without_poles_or_current_is_shown_as_unread(): void
    {
        $parsed = $this->reader()->parse('{"devices":[{"board":"ГЕТ","kind":"МАП","text":"неясен автомат","quantity":1}],"unread":[]}');

        $this->assertSame([], $parsed['circuits']);
        $this->assertSame('неясен автомат', $parsed['unread'][0]['text']);
        $this->assertStringContainsString('не се мапва', $parsed['unread'][0]['reason']);
    }

    private function reader(): ScheduleClaudeReader
    {
        return new ScheduleClaudeReader(new ClaudeClient, new ScheduleTableReader(new AutocadScheduleParser));
    }
}
