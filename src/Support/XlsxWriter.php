<?php

namespace LaraSlice\Support;

/**
 * Minimal XLSX writer (no dependencies): one worksheet,
 * inline strings for text, native cells for numbers. Enough
 * for directory exports that open in Excel/Sheets/Numbers
 * and parse with any standard reader — the format the
 * source systems export, replacing client-side CSV.
 */
final class XlsxWriter
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    public static function build(array $headers, array $rows): string
    {
        $sheetRows = [];
        $sheetRows[] = self::rowXml(1, $headers, true);
        $r = 2;
        foreach ($rows as $row) {
            $sheetRows[] = self::rowXml($r, array_values($row), false);
            $r++;
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $sheetRows).'</sheetData></worksheet>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'</Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function rowXml(int $index, array $values, bool $isHeader): string
    {
        $cells = '';
        foreach ($values as $i => $value) {
            $ref = self::columnLetter($i + 1).$index;
            if (! $isHeader && is_numeric($value) && ! is_bool($value)) {
                $cells .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                continue;
            }
            $text = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'Yes' : 'No',
                is_scalar($value) => (string) $value,
                // JSON-cast attributes (roles, meta) export as
                // their JSON text instead of crashing the cast.
                default => (string) json_encode($value),
            };
            $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'
                .htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8')
                .'</t></is></c>';
        }

        return '<row r="'.$index.'">'.$cells.'</row>';
    }

    private static function columnLetter(int $n): string
    {
        $letters = '';
        while ($n > 0) {
            $n--;
            $letters = chr(65 + ($n % 26)).$letters;
            $n = intdiv($n, 26);
        }

        return $letters;
    }
}
