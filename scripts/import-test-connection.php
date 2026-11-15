<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require_once $root.'/vendor/autoload.php';
$credentials = json_decode(file_get_contents($root.'/.runtime/mysql-test-credentials.json'), true, flags: JSON_THROW_ON_ERROR);
echo "Import QA: testing; mysql; 127.0.0.1:33078; bshop_testing_single.\n";
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->detectEnvironment(fn () => 'testing');
config(['app.env' => 'testing', 'database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 33078, 'database.connections.mysql.database' => 'bshop_testing_single', 'database.connections.mysql.username' => $credentials['user'], 'database.connections.mysql.password' => $credentials['password']]);
DB::purge('mysql');
$server = DB::selectOne('SELECT DATABASE() AS db, VERSION() AS version, @@port AS port, @@bind_address AS address, @@datadir AS directory');
if ($server->db !== 'bshop_testing_single' || ! str_starts_with($server->version, '8.4.') || (int) $server->port !== 33078 || $server->address !== '127.0.0.1' || strcasecmp(rtrim(str_replace('\\', '/', $server->directory), '/'), str_replace('\\', '/', realpath($root.'/.runtime/mysql-data'))) !== 0) {
    throw new RuntimeException('Unexpected import test destination.');
}
echo "Verified MySQL $server->version, local test bind and project datadir.\n";

return $app;
