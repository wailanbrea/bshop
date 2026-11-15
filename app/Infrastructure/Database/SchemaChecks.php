<?php

namespace App\Infrastructure\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Versioned CHECKs: native MySQL, equivalent INSERT/UPDATE guards for local SQLite. */
final class SchemaChecks
{
    public static function add(string $table, array $constraints): void
    {
        foreach ($constraints as $name => $expression) {
            self::identifier($table);
            self::identifier($name);
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($expression)");
            } elseif (DB::getDriverName() === 'sqlite') {
                $columns = Schema::getColumnListing($table);
                // Only rewrite SQL identifiers outside quoted string literals.
                $parts = preg_split("/('(?:''|[^'])*')/", $expression, -1, PREG_SPLIT_DELIM_CAPTURE);
                foreach ($parts as $i => $part) {
                    if ($i % 2 === 0) {
                        $parts[$i] = preg_replace_callback('/\b[a-zA-Z_][a-zA-Z_0-9]*\b/', fn ($match) => in_array($match[0], $columns, true) ? 'NEW."'.$match[0].'"' : $match[0], $part);
                    }
                }
                $condition = implode('', $parts);
                foreach (['INSERT' => 'ins', 'UPDATE' => 'upd'] as $operation => $suffix) {
                    DB::unprepared("CREATE TRIGGER \"{$name}_$suffix\" BEFORE $operation ON \"$table\" WHEN NOT ($condition) BEGIN SELECT RAISE(ABORT, '$name'); END");
                }
            } else {
                throw new InvalidArgumentException('BShop migrations support MySQL and local SQLite only.');
            }
        }
    }

    public static function drop(string $table, array $names): void
    {
        foreach ($names as $name) {
            self::identifier($table);
            self::identifier($name);
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `$table` DROP CHECK `$name`");
            } else {
                DB::statement("DROP TRIGGER IF EXISTS \"{$name}_ins\"");
                DB::statement("DROP TRIGGER IF EXISTS \"{$name}_upd\"");
            }
        }
    }

    private static function identifier(string $value): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,59}$/', $value)) {
            throw new InvalidArgumentException('Invalid versioned schema identifier.');
        }
    }
}
