<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
$destination = str_replace('\\', '/', database_path('bshop-business.sqlite'));
if (! $app->environment('local') || $connection->getDriverName() !== 'sqlite' || str_replace('\\', '/', $connection->getDatabaseName()) !== $destination) {
    throw new RuntimeException('Import requires the NEW local single-business SQLite file.');
}
echo "Destination: local; sqlite; no host; $destination\n";
$export = json_decode(file_get_contents(base_path('.runtime/single-business-source.json')), true, flags: JSON_THROW_ON_ERROR);
if (! hash_equals($export['source_sha256'], hash_file('sha256', $export['source_backup']))) {
    throw new RuntimeException('Source backup changed.');
}
if (DB::table('users')->exists() || DB::table('business_profile')->exists()) {
    throw new RuntimeException('Destination contains business data. No overwrite performed.');
}
$schema = json_decode(file_get_contents(base_path('database/schema/bshop-single.json')), true, flags: JSON_THROW_ON_ERROR);
$order = ['business_profile', 'users', 'roles', 'role_user', 'stores', 'warehouses', 'store_user', 'audit_logs', 'team_invitations', 'invitation_store_access', ...array_merge(...array_values($schema['groups']))];
DB::transaction(function () use ($order, $export) {
    foreach ($order as $table) {
        $rows = $export['tables'][$table] ?? [];
        foreach ($rows as $row) {
            DB::table($table)->insert($row);
        }
        if (DB::table($table)->count() !== count($rows)) {
            throw new RuntimeException('Count mismatch for '.$table);
        }
        if ($rows !== []) {
            echo "$table: ".count($rows)." rows imported and verified.\n";
        }
    }
    foreach (['business_settings', 'storage_quota'] as $table) {
        if (! DB::table($table)->exists()) {
            DB::table($table)->insert(['id' => 1]);
        }
    }
    if (DB::select('PRAGMA foreign_key_check') !== []) {
        throw new RuntimeException('Imported relations failed verification.');
    }
    foreach ($export['tables']['users'] as $source) {
        if (! hash_equals($source['password'], DB::table('users')->where('id', $source['id'])->value('password'))) {
            throw new RuntimeException('Password hash mismatch.');
        }
    }
});
echo "Verified single-business import. Source database, backup and other legacy profiles preserved unchanged.\n";
