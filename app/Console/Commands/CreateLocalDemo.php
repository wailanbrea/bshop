<?php

namespace App\Console\Commands;

use App\Domain\Business\InstallBusiness;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateLocalDemo extends Command
{
    protected $signature = 'bshop:demo';

    protected $description = 'Create KeylaShop and a random-password demo user in the isolated local SQLite database only.';

    public function handle(InstallBusiness $install): int
    {
        $connection = DB::connection();
        if (! app()->environment('local') || $connection->getDriverName() !== 'sqlite'
            || str_replace('\\', '/', $connection->getDatabaseName()) !== str_replace('\\', '/', database_path('bshop-business.sqlite'))) {
            $this->error('Demo creation is restricted to local database/bshop-business.sqlite.');

            return self::FAILURE;
        }
        $this->call('bshop:environment');
        if (User::where('email', 'demo@bshop.test')->exists() || BusinessProfile::exists()) {
            $this->info('Demo already exists. No users or passwords were changed.');

            return self::SUCCESS;
        }
        $password = Str::password(20);
        DB::transaction(function () use ($install, $password) {
            $user = User::create(['name' => 'Demo KeylaShop', 'email' => 'demo@bshop.test', 'password' => $password]);
            $install->create($user, [
                'name' => 'KeylaShop', 'default_currency' => 'USD',
                'timezone' => 'America/Santo_Domingo', 'store_name' => 'Sucursal principal',
            ]);
        });
        $this->info('Synthetic local demo only. Currency USD confirmed by the owner.');
        $this->line('Email: demo@bshop.test');
        $this->line('Password (shown once): '.$password);

        return self::SUCCESS;
    }
}
