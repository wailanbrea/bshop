<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Strict UTF-8 CSV with RFC-style quoting and physical source line numbers. */
final class CsvCatalogReader
{
    public const COLUMNS = ['product_key', 'name', 'sku', 'sale_price', 'cost_price', 'barcode', 'product_type', 'unit_code', 'track_stock', 'category_id', 'brand_id', 'attribute_value_ids', 'description', 'status', 'variant_active'];

    public const MAX_BYTES = 2097152;

    public function read(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_BYTES || ! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            $this->invalid('El archivo debe ser CSV UTF-8, sin bytes nulos y de hasta 2 MiB.');
        }
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        $first = strtok($bytes, "\r\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $records = $this->records($bytes, $delimiter);
        if (count($records) < 2) {
            $this->invalid('El CSV debe incluir cabecera y al menos una fila de datos.');
        }
        $headers = array_map(fn ($header) => strtolower(trim($header)), array_shift($records)['cells']);
        if (count(array_unique($headers)) !== count($headers) || array_diff($headers, self::COLUMNS) !== [] || array_diff(['name', 'sku', 'sale_price'], $headers) !== []) {
            $this->invalid('Cabecera no válida. Usa la plantilla: name, sku y sale_price son obligatorios; no se admiten columnas desconocidas ni repetidas.');
        }
        $rows = [];
        $groups = [];
        foreach ($records as $record) {
            $line = $record['line'];
            $cells = $record['cells'];
            $sourceErrors = count($cells) !== count($headers) ? [['field' => 'row', 'message' => 'La cantidad de columnas no coincide con la cabecera. Revisa separadores y comillas.']] : [];
            $raw = array_fill_keys(self::COLUMNS, '');
            foreach ($headers as $index => $header) {
                $raw[$header] = trim($cells[$index] ?? '');
            }
            $raw['sku'] = Str::upper($raw['sku']);
            $raw['barcode'] = Str::upper($raw['barcode']);
            $key = $raw['product_key'] === '' ? 'row:'.$line : 'group:'.$raw['product_key'];
            if (mb_strlen($raw['product_key']) > 100) {
                $sourceErrors[] = ['field' => 'product_key', 'message' => 'El identificador del grupo no puede superar 100 caracteres.'];
            }
            $type = $raw['product_type'] === '' ? 'physical' : $raw['product_type'];
            $data = ['name' => $raw['name'], 'description' => $raw['description'] === '' ? null : $raw['description'], 'product_type' => $type, 'unit_code' => $raw['unit_code'] === '' ? 'unit' : $raw['unit_code'], 'track_stock' => $this->boolean($raw['track_stock'], $type !== 'service'), 'category_id' => $this->id($raw['category_id']), 'brand_id' => $this->id($raw['brand_id']), 'status' => $raw['status'] === '' ? 'active' : $raw['status']];
            $variant = ['sku' => $raw['sku'], 'barcode' => $raw['barcode'] === '' ? null : $raw['barcode'], 'sale_price' => $this->decimal($raw['sale_price']), 'attribute_value_ids' => $raw['attribute_value_ids'] === '' ? [] : array_map(fn ($id) => $this->id(trim($id)), explode('|', $raw['attribute_value_ids'])), 'is_active' => $this->boolean($raw['variant_active'], true)];
            if ($raw['cost_price'] !== '') {
                $variant['cost_price'] = $this->decimal($raw['cost_price']);
            }
            $rows[$line] = ['row' => $line, 'product_key' => $raw['product_key'], 'name' => $raw['name'], 'sku' => $raw['sku'], 'barcode' => $raw['barcode'], 'sale_price' => $variant['sale_price'], 'cost_price' => $variant['cost_price'] ?? null, 'product_type' => $type, 'source_errors' => $sourceErrors, 'errors' => []];
            if (! isset($groups[$key])) {
                $groups[$key] = ['key' => $raw['product_key'], 'data' => $data + ['variants' => []], 'rows' => [], 'mismatch' => false];
            }
            $firstData = $groups[$key]['data'];
            unset($firstData['variants']);
            if ($firstData !== $data) {
                $groups[$key]['mismatch'] = true;
            }
            $groups[$key]['data']['variants'][] = $variant;
            $groups[$key]['rows'][] = $line;
        }
        foreach ($groups as $group) {
            if ($group['mismatch']) {
                foreach ($group['rows'] as $line) {
                    $rows[$line]['source_errors'][] = ['field' => 'product_key', 'message' => 'Las filas del mismo grupo deben tener iguales datos generales del producto.'];
                }
            }
        }
        foreach (['sku', 'barcode'] as $field) {
            $seen = [];
            foreach ($rows as $line => $row) {
                if ($row[$field] !== '') {
                    $seen[$row[$field]][] = $line;
                }
            }
            foreach ($seen as $lines) {
                if (count($lines) > 1) {
                    foreach ($lines as $line) {
                        $rows[$line]['source_errors'][] = ['field' => $field, 'message' => ($field === 'sku' ? 'El SKU' : 'El código de barras').' se repite en las filas '.implode(', ', $lines).'.'];
                    }
                }
            }
        }

        return ['version' => 1, 'delimiter' => $delimiter, 'headers' => $headers, 'groups' => array_values($groups), 'rows' => array_values($rows)];
    }

    private function records(string $bytes, string $delimiter): array
    {
        $rows = [];
        $cells = [];
        $field = '';
        $state = 'start';
        $line = 1;
        $start = 1;
        $finish = function () use (&$rows, &$cells, &$field, &$start): void {
            if (strlen($field) > 65536 || count($cells) >= 64) {
                $this->invalid('El CSV supera el límite técnico de 64 KiB por celda o 64 columnas por fila.');
            }
            $cells[] = $field;
            if (array_filter($cells, fn ($value) => trim($value) !== '') !== []) {
                $rows[] = ['line' => $start, 'cells' => $cells];
            }
            if (count($rows) > 501) {
                $this->invalid('Máximo 500 filas de datos por importación.');
            }
            $cells = [];
            $field = '';
        };
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            if (strlen($field) > 65536 || count($cells) >= 64) {
                $this->invalid('El CSV supera el límite técnico de 64 KiB por celda o 64 columnas por fila.');
            }
            $char = $bytes[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($bytes[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $state = 'after';
                    }
                } elseif ($char === "\r" || $char === "\n") {
                    if ($char === "\r" && ($bytes[$i + 1] ?? '') === "\n") {
                        $i++;
                    }
                    $field .= "\n";
                    $line++;
                } else {
                    $field .= $char;
                }

                continue;
            }
            if ($char === $delimiter) {
                $cells[] = $field;
                $field = '';
                $state = 'start';

                continue;
            }
            if ($char === "\r" || $char === "\n") {
                $finish();
                if ($char === "\r" && ($bytes[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $line++;
                $start = $line;
                $state = 'start';

                continue;
            }
            if ($char === '"' && $state === 'start') {
                $state = 'quoted';

                continue;
            }
            if ($char === '"' || ($state === 'after' && $char !== ' ' && $char !== "\t")) {
                $this->invalid("CSV mal formado en la línea $line. Revisa las comillas.");
            }
            if ($state !== 'after') {
                $field .= $char;
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            $this->invalid("Hay comillas sin cerrar en la línea $start.");
        }
        if ($cells !== [] || $field !== '' || $state === 'after') {
            $finish();
        }

        return $rows;
    }

    private function id(string $value): mixed
    {
        return $value === '' ? null : (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false ? (int) $value : $value);
    }

    private function boolean(string $value, bool $default): mixed
    {
        return match (mb_strtolower($value)) {
            '' => $default, '1', 'true', 'si', 'sí' => true, '0', 'false', 'no' => false, default => $value
        };
    }

    private function decimal(string $value): string
    {
        return preg_match('/^[0-9]+,[0-9]{1,4}$/D', $value) ? str_replace(',', '.', $value) : $value;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
