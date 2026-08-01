<?php

namespace App\Services;

/**
 * Lot 10D — Writes a query result set to a SpreadsheetML 2003 XML file, which
 * opens natively in Excel and LibreOffice without any PHP library dependency.
 *
 * The file is written row by row to a resource handle to keep memory bounded;
 * the flush interval mirrors the CSV paging strategy so callers can interleave
 * progress saves without keeping all data in memory.
 *
 * Security note: all values are XML-escaped before serialisation. No macros,
 * styles or embedded content are produced.
 */
class XlsxWriter
{
    /** Maximum string length stored in a cell (truncated silently). */
    private const int MAX_CELL_LENGTH = 32767;

    /**
     * Write the SpreadsheetML preamble and column headers to the handle.
     *
     * @param  resource  $handle
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $options  Accepts: sheet_name (string, max 31 chars).
     */
    public function writeHeader($handle, array $columns, array $options = []): void
    {
        $sheetName = $this->sanitiseSheetName((string) ($options['sheet_name'] ?? 'Export'));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>'."\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'."\n";
        $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";
        $xml .= ' <Worksheet ss:Name="'.$this->escapeXml($sheetName).'">'."\n";
        $xml .= '  <Table>'."\n";

        fwrite($handle, $xml);
        $this->writeRow($handle, $columns, header: true);
    }

    /**
     * Write one data row.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     */
    public function writeDataRow($handle, array $row, array $columns): void
    {
        $values = array_map(
            fn (string $col): mixed => $row[$col] ?? null,
            $columns,
        );

        $this->writeRow($handle, $values, header: false);
    }

    /**
     * Close the worksheet and workbook tags.
     *
     * @param  resource  $handle
     */
    public function writeFooter($handle): void
    {
        fwrite($handle, "  </Table>\n </Worksheet>\n</Workbook>\n");
    }

    /**
     * @param  resource  $handle
     * @param  list<mixed>  $values
     */
    private function writeRow($handle, array $values, bool $header): void
    {
        fwrite($handle, "   <Row>\n");

        foreach ($values as $value) {
            if ($header) {
                $cell = '    <Cell><Data ss:Type="String">'.$this->escapeXml((string) $value).'</Data></Cell>';
            } else {
                [$type, $serialised] = $this->serialise($value);
                $cell = '    <Cell><Data ss:Type="'.$type.'">'.$this->escapeXml($serialised).'</Data></Cell>';
            }

            fwrite($handle, $cell."\n");
        }

        fwrite($handle, "   </Row>\n");
    }

    /**
     * @return array{string, string} [SpreadsheetML type, serialised value]
     */
    private function serialise(mixed $value): array
    {
        if ($value === null) {
            return ['String', ''];
        }

        if (is_bool($value)) {
            return ['Number', $value ? '1' : '0'];
        }

        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value)) {
                return ['String', (string) $value];
            }

            return ['Number', (string) $value];
        }

        if (is_array($value) || is_object($value)) {
            $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return ['String', mb_substr($json, 0, self::MAX_CELL_LENGTH)];
        }

        $str = mb_substr((string) $value, 0, self::MAX_CELL_LENGTH);

        return ['String', $str];
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sanitiseSheetName(string $name): string
    {
        // Remove characters forbidden in Excel sheet names.
        $cleaned = preg_replace('/[\/\\\?\*\[\]:]/', '', $name) ?? '';
        $cleaned = trim($cleaned);

        if ($cleaned === '') {
            $cleaned = 'Export';
        }

        return mb_substr($cleaned, 0, 31);
    }
}
