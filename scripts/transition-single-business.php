<?php

$root = dirname(__DIR__);
$archive = $root.'/.runtime/legacy-multibusiness';
$mode = $argv[1] ?? 'plan';
if ($mode === 'plan' || $mode === 'archive') {
    $patterns = [
        'app/Domain/Tenancy/*.php', 'app/Models/Tenant*.php', 'app/Models/Concerns/BelongsToTenant.php',
        'app/Jobs/Middleware/WithTenantContext.php', 'app/Http/Middleware/ResolveTenant.php', 'app/Http/Requests/TenantRequest.php',
        'database/migrations/*.php', 'database/schema/bshop-f2.json', 'database/seeders/F2ScenarioSeeder.php',
        'tests/Feature/TenancyTest.php', 'tests/Feature/TeamAccessTest.php', 'tests/Feature/F2SchemaTest.php',
        'tests/Support/F2IntegrityScenario.php', 'resources/js/components/OnboardingView.vue',
        'scripts/*f2*.php', 'scripts/*mysql*.php', 'scripts/document-reference-schema.php',
    ];
    $paths = [];
    foreach ($patterns as $pattern) {
        foreach (glob($root.'/'.$pattern) as $path) {
            $paths[substr($path, strlen($root) + 1)] = $path;
        }
    }
    ksort($paths);
    if ($mode === 'plan') {
        if (is_dir($archive)) {
            throw new RuntimeException('Archive already exists.');
        }
        echo "SIMULATION: preserve active multi-business code outside application paths.\nDestination: $archive\nFiles: ".count($paths)."\n";
        foreach ($paths as $relative => $path) {
            echo "$relative -> $archive/$relative\n";
        }
        file_put_contents($root.'/.runtime/single-business-archive-plan.json', json_encode(array_map(fn ($path) => hash_file('sha256', $path), $paths), JSON_THROW_ON_ERROR));
        exit;
    }
    $plan = json_decode(file_get_contents($root.'/.runtime/single-business-archive-plan.json'), true, flags: JSON_THROW_ON_ERROR);
    if (array_keys($plan) !== array_keys($paths)) {
        throw new RuntimeException('Archive paths changed after simulation.');
    }
    foreach ($paths as $relative => $path) {
        if (! hash_equals($plan[$relative], hash_file('sha256', $path))) {
            throw new RuntimeException('Source changed: '.$relative);
        }
    }
    foreach (['app', 'resources', 'routes', 'tests'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $target = $archive.'/snapshot/'.$relative;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            if (! copy($file->getPathname(), $target) || hash_file('sha256', $target) !== hash_file('sha256', $file->getPathname())) {
                throw new RuntimeException('Snapshot verification failed.');
            }
        }
    }
    foreach ($paths as $relative => $path) {
        $target = $archive.'/'.$relative;
        if (file_exists($target)) {
            throw new RuntimeException('Archive destination occupied.');
        }
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        if (! rename($path, $target)) {
            throw new RuntimeException('Archive move failed.');
        }
    }
    echo 'Archived '.count($paths)." files reversibly. No database was deleted or reset.\n";
    exit;
}
if ($mode !== 'generate') {
    throw new RuntimeException('Modes: plan, archive, generate.');
}
$schema = json_decode(file_get_contents($archive.'/database/schema/bshop-f2.json'), true, flags: JSON_THROW_ON_ERROR);
$renameTable = ['tenant_settings' => 'business_settings', 'tenant_storage_quotas' => 'storage_quota', 'tenant_roles' => 'roles', 'tenant_memberships' => 'users'];
$renameColumn = ['membership_id' => 'user_id', 'performed_by_membership_id' => 'performed_by_user_id', 'created_by_membership_id' => 'created_by_user_id', 'uploaded_by_membership_id' => 'uploaded_by_user_id'];
$mapped = [];
$groups = [];
foreach ($schema['groups'] as $suffix => $names) {
    $suffix = str_replace('create_saas_and_outbox_tables', 'create_idempotency_and_outbox_tables', $suffix);
    foreach ($names as $name) {
        if (in_array($name, ['saas_plans', 'tenant_subscriptions'], true)) {
            continue;
        }
        $table = $renameTable[$name] ?? $name;
        $definition = $schema['tables'][$name];
        $columns = [];
        foreach ($definition['columns'] as $old => $column) {
            if ($old === 'tenant_id') {
                continue;
            }
            $new = $renameColumn[$old] ?? $old;
            $column['COLUMN_NAME'] = $new;
            $column['TABLE_NAME'] = $table;
            $columns[$new] = $column;
        }
        $map = fn (array $cols) => array_values(array_map(fn ($col) => $renameColumn[$col] ?? $col, array_filter($cols, fn ($col) => $col !== 'tenant_id')));
        $indexes = [];
        foreach ($definition['indexes'] ?? [] as $label => $entry) {
            $entry['columns'] = $map($entry['columns']);
            if ($entry['columns'] === [] || ($label !== 'PRIMARY' && $entry['columns'] === ['id'])) {
                continue;
            }
            $indexes[$label] = $entry;
        }
        $foreign = [];
        foreach ($definition['foreign_keys'] ?? [] as $label => $entry) {
            if ($entry['table'] === 'tenants') {
                continue;
            }
            $entry['columns'] = $map($entry['columns']);
            $entry['references'] = $map($entry['references']);
            $entry['table'] = $renameTable[$entry['table']] ?? $entry['table'];
            $foreign[$label] = $entry;
        }
        if (in_array($table, ['business_settings', 'storage_quota'], true)) {
            $columns = ['id' => ['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'tinyint unsigned', 'DATA_TYPE' => 'tinyint', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => 1]] + $columns;
            $indexes['PRIMARY'] = ['columns' => ['id'], 'unique' => true];
            $definition['checks']['chk_'.$table.'_singleton'] = 'id = 1';
        }
        $mapped[$table] = ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreign, 'checks' => $definition['checks'] ?? []];
        $groups[$suffix][] = $table;
    }
}
$literal = function ($value) use (&$literal): string {
    if (! is_array($value)) {
        return var_export($value, true);
    }
    $entries = [];
    $list = array_is_list($value);
    foreach ($value as $key => $entry) {
        $entries[] = ($list ? '' : $literal($key).' => ').$literal($entry);
    }

    return '['.implode(', ', $entries).']';
};
$files = [];
foreach ($groups as $suffix => $names) {
    $code = "<?php\n\nuse App\\Infrastructure\\Database\\SchemaChecks;\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\n// Single-business installation: no tenant/company scope or SaaS subscription tables.\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n";
    foreach ($names as $table) {
        $definition = $mapped[$table];
        $auto = ($definition['indexes']['PRIMARY']['columns'] ?? []) === ['id'] && ! in_array($table, ['business_settings', 'storage_quota'], true);
        $code .= '        Schema::create('.$literal($table).", function (Blueprint \$table) {\n";
        foreach ($definition['columns'] as $name => $column) {
            $type = $column['COLUMN_TYPE'];
            $n = $literal($name);
            preg_match('/\((\d+)(?:,(\d+))?\)/', $type, $size);
            $method = match ($column['DATA_TYPE']) {
                'bigint' => $auto && $name === 'id' ? "id($n)" : (str_contains($type, 'unsigned') ? "unsignedBigInteger($n)" : "bigInteger($n)"),
                'int' => str_contains($type, 'unsigned') ? "unsignedInteger($n)" : "integer($n)",
                'smallint' => str_contains($type, 'unsigned') ? "unsignedSmallInteger($n)" : "smallInteger($n)",
                'tinyint' => str_contains($type, '(1)') ? "boolean($n)" : (str_contains($type, 'unsigned') ? "unsignedTinyInteger($n)" : "tinyInteger($n)"),
                'varchar' => "string($n, {$size[1]})", 'char' => "char($n, {$size[1]})", 'decimal' => "decimal($n, {$size[1]}, {$size[2]})",
                'datetime' => "dateTime($n, ".($size[1] ?? 0).')', 'date' => "date($n)", 'json' => "json($n)", 'text' => "text($n)",
                default => throw new RuntimeException('Unexpected type '.$type),
            };
            $line = '$table->'.$method;
            if ($column['IS_NULLABLE'] === 'YES') {
                $line .= '->nullable()';
            }
            if (($column['generated'] ?? null) !== null) {
                $line .= '->virtualAs('.$literal($column['generated']).')';
            } elseif ($column['COLUMN_DEFAULT'] !== null) {
                $default = in_array($column['DATA_TYPE'], ['bigint', 'int', 'smallint', 'tinyint'], true) ? (int) $column['COLUMN_DEFAULT'] : $column['COLUMN_DEFAULT'];
                $line .= '->default('.$literal($default).')';
            }
            $code .= '            '.$line.";\n";
        }
        foreach ($definition['indexes'] as $label => $entry) {
            if ($label === 'PRIMARY' && $auto) {
                continue;
            }
            $method = $label === 'PRIMARY' ? 'primary' : ($entry['unique'] ? 'unique' : 'index');
            $code .= '            $table->'.$method.'('.$literal($entry['columns']).($label === 'PRIMARY' ? '' : ', '.$literal($label)).");\n";
        }
        foreach ($definition['foreign_keys'] as $label => $entry) {
            $code .= '            $table->foreign('.$literal($entry['columns']).', '.$literal($label).')->references('.$literal($entry['references']).')->on('.$literal($entry['table']).");\n";
        }
        $code .= "        });\n";
        if ($definition['checks'] !== []) {
            $code .= '        SchemaChecks::add('.$literal($table).', '.$literal($definition['checks']).");\n";
        }
    }
    $code .= "    }\n\n    public function down(): void\n    {\n";
    foreach (array_reverse($names) as $table) {
        $code .= '        Schema::dropIfExists('.$literal($table).");\n";
    }
    $code .= "    }\n};\n";
    $files[$root.'/database/migrations/2026_11_12_'.$suffix.'.php'] = $code;
}
foreach (['0001_01_01_000001_create_cache_table.php', '0001_01_01_000002_create_jobs_table.php'] as $name) {
    $files[$root.'/database/migrations/'.$name] = file_get_contents($archive.'/database/migrations/'.$name);
}
$files[$root.'/database/schema/bshop-single.json'] = json_encode(['architecture' => 'single-business', 'tables' => $mapped, 'groups' => $groups], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
foreach ($files as $path => $code) {
    if (file_exists($path)) {
        throw new RuntimeException('Refusing to overwrite '.$path);
    }
}
foreach ($files as $path => $code) {
    file_put_contents($path, $code);
}
echo "Generated single-business domain migrations; no tenant_id columns or subscription tables.\n";
