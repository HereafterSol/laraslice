<?php

namespace LaraSlice\Tests\Unit;

use LaraSlice\Support\XlsxWriter;
use LaraSlice\Tests\TestCase;

class XlsxWriterTest extends TestCase
{
    public function test_builds_a_readable_workbook(): void
    {
        $bytes = XlsxWriter::build(['Name', 'Total'], [['INV#001', 480], ['INV#002', 4100]]);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        $this->assertStringContainsString('INV#001', $sheet);
        $this->assertStringContainsString('<v>4100</v>', $sheet);
        $this->assertStringContainsString('Name', $sheet);
    }

    public function test_escapes_xml_in_strings(): void
    {
        $bytes = XlsxWriter::build(['A&B'], [['<x> & "y"']]);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        $this->assertStringContainsString('A&amp;B', $sheet);
        $this->assertStringContainsString('&lt;x&gt;', $sheet);
    }
}
