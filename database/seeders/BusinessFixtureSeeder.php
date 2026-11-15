<?php

namespace Database\Seeders;

use App\Domain\Business\InstallBusiness;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BusinessFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $connection = DB::connection();
        $safe = $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        $safe = $safe || ($connection->getDriverName() === 'mysql' && $connection->getConfig('host') === '127.0.0.1' && (int) $connection->getConfig('port') === 33078 && $connection->getDatabaseName() === 'bshop_testing_single');
        if (! app()->environment('testing') || ! $safe) {
            throw new RuntimeException('Fixtures are restricted to the isolated single-business test databases.');
        }
        $owner = User::factory()->create(['email' => 'owner@business.test']);
        app(InstallBusiness::class)->create($owner, ['name' => 'Negocio QA', 'default_currency' => 'DOP', 'timezone' => 'America/Santo_Domingo', 'store_name' => 'Principal']);
        $second = Store::create(['name' => 'Segunda sucursal', 'code' => 'SEGUNDA']);
        Warehouse::create(['store_id' => $second->id, 'name' => 'Almacén segunda', 'is_default' => true]);
        DB::table('store_user')->insert(['user_id' => $owner->id, 'store_id' => $second->id]);
        Customer::factory()->count(2)->create();
        Employee::factory()->count(2)->create();
        $product = Product::factory()->create(['name' => 'Camiseta QA']);
        foreach (['M', 'L'] as $size) {
            ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'QA-'.$size, 'combination_key' => 'size-'.$size]);
        }
        $service = Product::factory()->service()->create(['name' => 'Servicio QA']);
        ProductVariant::factory()->create(['product_id' => $service->id, 'sku' => 'QA-SERVICE']);
    }
}
