<?php

use Database\Seeders\BusinessFixtureSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$credentials = json_decode(file_get_contents($root.'/.runtime/mysql-test-credentials.json'), true, flags: JSON_THROW_ON_ERROR);
echo "Destination: testing; MySQL; 127.0.0.1:33078; NEW bshop_testing_single.\n";
$admin = new PDO('mysql:host=127.0.0.1;port=33078;dbname=mysql;charset=utf8mb4', 'root', $credentials['root_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = $admin->query('SELECT VERSION() AS version, @@port AS port, @@bind_address AS bind_address, @@datadir AS data_directory')->fetch(PDO::FETCH_OBJ);
if (! str_starts_with($server->version, '8.4.') || (int) $server->port !== 33078 || $server->bind_address !== '127.0.0.1' || strcasecmp(rtrim(str_replace('\\', '/', $server->data_directory), '/'), str_replace('\\', '/', realpath($root.'/.runtime/mysql-data'))) !== 0) {
    throw new RuntimeException('Unexpected MySQL instance.');
}
if (($argv[1] ?? '') === 'provision') {
    if ((int) $admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'bshop_testing_single'")->fetchColumn() !== 0) {
        throw new RuntimeException('Single-business test schema already exists; no overwrite.');
    }
    $admin->exec('CREATE DATABASE bshop_testing_single CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    $admin->exec("GRANT ALL PRIVILEGES ON bshop_testing_single.* TO 'bshop_test'@'127.0.0.1'");
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->detectEnvironment(fn () => 'testing');
config(['app.env' => 'testing', 'database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 33078, 'database.connections.mysql.database' => 'bshop_testing_single', 'database.connections.mysql.username' => $credentials['user'], 'database.connections.mysql.password' => $credentials['password']]);
DB::purge('mysql');
if (($argv[1] ?? '') === 'provision') {
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    echo Artisan::output();
    Artisan::call('db:seed', ['--class' => BusinessFixtureSeeder::class, '--force' => true]);
    echo Artisan::output();
}
$database = DB::selectOne('SELECT DATABASE() AS db, VERSION() AS version');
if ($database->db !== 'bshop_testing_single') {
    throw new RuntimeException('Unexpected effective schema.');
}
$tables = DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$database->db]);
$scopeColumns = (int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'tenant_id'", [$database->db])->n;
$removed = (int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('tenants','tenant_memberships','saas_plans','tenant_subscriptions')", [$database->db])->n;
if ($scopeColumns !== 0 || $removed !== 0 || DB::table('business_profile')->count() !== 1) {
    throw new RuntimeException('Single-business invariants failed.');
}
echo 'PASS: '.count($tables)." tables; zero tenant_id columns, zero multi-business/SaaS tables, one business profile.\n";
$reject = function (string $name, int $code, Closure $operation): void {
    try {
        $operation();
    } catch (QueryException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== $code) {
            throw $e;
        } echo "PASS: $name (MySQL $code).\n";

        return;
    }
    throw new RuntimeException('Constraint failed: '.$name);
};
DB::beginTransaction();
try {
    $reject('second business profile', 3819, fn () => DB::table('business_profile')->insert(['id' => 2, 'name' => 'Not allowed', 'default_currency' => 'USD', 'timezone' => 'UTC']));
    $store = DB::table('stores')->first();
    $employee = DB::table('employees')->first();
    $reject('warehouse references a real branch', 1452, fn () => DB::table('warehouses')->insert(['store_id' => 999999, 'name' => 'Invalid']));
    $reject('only one active default warehouse per branch', 1062, fn () => DB::table('warehouses')->insert(['store_id' => $store->id, 'name' => 'Duplicate', 'is_default' => true]));
    $reject('salary requires a period', 3819, fn () => DB::table('employee_compensation_plans')->insert(['employee_id' => $employee->id, 'pay_type' => 'salary', 'currency' => 'DOP', 'fixed_salary' => '100.0000', 'effective_from' => '2026-01-01']));
    $reject('photo quota maximum five', 3819, fn () => DB::table('storage_quota')->where('id', 1)->update(['max_images_per_product' => 6]));
} finally {
    DB::rollBack();
}
$foreign = (int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'", [$database->db])->n;
$checks = (int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = 'CHECK'", [$database->db])->n;
echo "Verified single-business MySQL $database->version: $foreign FK and $checks native CHECK constraints.\n";
