<?php

namespace App\Domain\Business;

use App\Models\BusinessProfile;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InstallBusiness
{
    public const SALES_PERMISSIONS = ['customers.view', 'customers.manage', 'sales.view', 'sales.create', 'invoices.view', 'payments.record'];

    public const OWNER_PERMISSIONS = ['business.manage', 'stores.view', 'stores.manage', 'warehouses.view', 'warehouses.manage', 'members.manage', 'audit.view', 'catalog.view', 'catalog.manage', 'catalog.costs', 'inventory.view', 'inventory.manage', ...self::SALES_PERMISSIONS, 'sales.cancel', 'payments.reverse', 'returns.receive', 'returns.adjust', 'refunds.record', 'credits.apply', 'credits.reverse', 'returns.reverse', 'refunds.reverse', 'adjustments.reverse'];

    public function create(User $owner, array $data): BusinessProfile
    {
        return DB::transaction(function () use ($owner, $data) {
            if (BusinessProfile::exists()) {
                throw new RuntimeException('This installation already has a business.');
            }
            $profile = BusinessProfile::create(['name' => $data['name'], 'default_currency' => $data['default_currency'], 'timezone' => $data['timezone']]);
            foreach (['owner' => ['Propietario', self::OWNER_PERMISSIONS], 'manager' => ['Administrador', ['stores.view', 'stores.manage', 'warehouses.view', 'warehouses.manage', 'audit.view', 'catalog.view', 'catalog.manage', 'catalog.costs', 'inventory.view', 'inventory.manage']], 'seller' => ['Vendedor', ['stores.view', 'warehouses.view', 'catalog.view', 'inventory.view']]] as $code => [$label, $permissions]) {
                $permissions = array_values(array_unique([...$permissions, ...self::SALES_PERMISSIONS, ...($code !== 'seller' ? ['sales.cancel', 'payments.reverse', 'returns.receive', 'returns.adjust', 'refunds.record', 'credits.apply', 'credits.reverse', 'returns.reverse', 'refunds.reverse', 'adjustments.reverse'] : [])]));
                $role = Role::create(compact('code', 'label', 'permissions'));
                if ($code === 'owner') {
                    $owner->roles()->attach($role->id);
                }
            }
            $store = Store::create(['name' => $data['store_name'], 'code' => 'CENTRAL']);
            DB::table('store_user')->insert(['user_id' => $owner->id, 'store_id' => $store->id]);
            Warehouse::create(['store_id' => $store->id, 'name' => 'Almacén principal', 'is_default' => true]);
            DB::table('business_settings')->insert(['id' => 1]);
            DB::table('storage_quota')->insert(['id' => 1]);
            app(AuditTrailService::class)->record('business.installed', $profile, actor: $owner);

            return $profile;
        });
    }
}
