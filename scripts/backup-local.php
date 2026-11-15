<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
$file = str_replace('\\', '/', $connection->getDatabaseName());
if (! $app->environment('local') || $connection->getDriverName() !== 'sqlite' || $file !== str_replace('\\', '/', database_path('bshop-business.sqlite'))) {
    throw new RuntimeException('Backup supports the isolated BShop local SQLite file only.');
}
$target = base_path('.runtime/bshop-backup-'.gmdate('Ymd-His').'.sqlite');
if (file_exists($target)) {
    throw new RuntimeException('Backup path already exists.');
}
$source = new SQLite3($file, SQLITE3_OPEN_READONLY);
$copy = new SQLite3($target);
if (! $source->backup($copy) || $copy->querySingle('PRAGMA integrity_check') !== 'ok') {
    throw new RuntimeException('Backup verification failed.');
}
echo "Environment: local; driver: sqlite; host: none; database: $file\n";
echo 'Verified backup: '.$target.'; tables: '.$copy->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")."\n";
$copy->close();
$source->close();
