<?php

use App\Domain\Business\InstallBusiness;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('This initializer is restricted to the disposable GitHub Actions QA job.');
}
require __DIR__.'/import-test-connection.php';
$tables = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA='bshop_testing_single' AND TABLE_TYPE='BASE TABLE'");
if ((int) $tables->n !== 0) {
    throw new RuntimeException('CI initialization requires an empty dedicated QA database; existing data was not touched.');
}
$exitCode = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
echo Artisan::output();
if ($exitCode !== 0) {
    throw new RuntimeException('QA migrations failed; business initialization was not started.');
}
$owner = User::factory()->create(['name' => 'Synthetic financial CI owner', 'email' => 'finance-owner@example.test', 'password' => Str::random(48)]);
app(InstallBusiness::class)->create($owner, ['name' => 'BShop financial concurrency CI', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Synthetic CI base store']);
echo "PASS: empty CI test schema migrated and single-business QA owner installed. No refresh/wipe/seed of an existing database.\n";
