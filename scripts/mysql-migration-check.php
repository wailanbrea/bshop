<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/import-test-connection.php';
$snapshot = [];
foreach (DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'bshop_testing_single'") as $table) {
    $name = $table->TABLE_NAME;
    $snapshot[$name] = ['schema' => ((array) DB::selectOne('SHOW CREATE TABLE `'.$name.'`'))['Create Table'], 'rows' => DB::table($name)->get()->map(fn ($row) => (array) $row)->all()];
}
$backup = base_path('.runtime/checked-mysql-backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json');
$json = json_encode($snapshot, JSON_THROW_ON_ERROR);
if (file_exists($backup) || file_put_contents($backup, $json) !== strlen($json) || ! hash_equals(hash('sha256', $json), hash_file('sha256', $backup))) {
    throw new RuntimeException('Schema/data backup failed verification.');
}
echo 'Verified full schema/data backup: '.$backup.' | tables: '.count($snapshot)."\n";
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
echo Artisan::output();
foreach ($snapshot as $name => $saved) {
    if (in_array($name, ['migrations', 'roles'], true)) {
        continue;
    }
    $before = $saved['rows'];
    if ($before === []) {
        if (DB::table($name)->exists()) {
            throw new RuntimeException('Unexpected rows in '.$name);
        }

        continue;
    }
    $after = DB::table($name)->select(array_keys($before[0]))->get()->map(fn ($row) => (array) $row)->all();
    $canonical = fn ($rows) => collect($rows)->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
    if ($canonical($before) !== $canonical($after)) {
        throw new RuntimeException('Preexisting values changed in '.$name);
    }
}
foreach ($snapshot['roles']['rows'] as $role) {
    $after = DB::table('roles')->find($role['id']);
    if ($after->code !== $role['code'] || $after->label !== $role['label'] || array_diff(json_decode($role['permissions'], true), json_decode($after->permissions, true))) {
        throw new RuntimeException('Existing role identity/permissions were not preserved.');
    }
}
echo "PASS: preexisting row values and permissions preserved; additive migrations verified.\n";
