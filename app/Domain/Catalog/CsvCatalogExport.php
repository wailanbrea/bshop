<?php

namespace App\Domain\Catalog;

/** CSV output safe to open in a spreadsheet, even when cells came from a rejected upload. */
final class CsvCatalogExport
{
    public static function cell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $text) || preg_match('/^[\t\r\n]/', $text) ? "'".$text : $text;
    }

    public static function write($stream, array $cells): void
    {
        fputcsv($stream, array_map(self::cell(...), $cells), ',', '"', '', "\r\n");
    }
}
