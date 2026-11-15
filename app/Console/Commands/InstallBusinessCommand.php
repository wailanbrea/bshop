<?php

namespace App\Console\Commands;

use App\Domain\Business\InstallBusiness;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class InstallBusinessCommand extends Command
{
    protected $signature = 'bshop:install';

    protected $description = 'Initialize this installation for its only business and owner.';

    public function handle(InstallBusiness $install): int
    {
        $this->call('bshop:environment');
        if (BusinessProfile::exists()) {
            $this->error('Este BShop ya pertenece a un negocio.');

            return self::FAILURE;
        }
        $data = ['name' => $this->ask('Nombre del negocio'), 'owner_name' => $this->ask('Nombre del propietario'), 'email' => strtolower($this->ask('Correo del propietario')), 'default_currency' => strtoupper($this->ask('Moneda', 'USD')), 'timezone' => $this->ask('Zona horaria', 'America/Santo_Domingo'), 'store_name' => $this->ask('Primera sucursal', 'Sucursal principal'), 'password' => $this->secret('Contraseña del propietario')];
        $validator = Validator::make($data, ['name' => ['required', 'string', 'max:160'], 'owner_name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:190', 'unique:users,email'], 'default_currency' => ['required', 'in:DOP,USD,EUR'], 'timezone' => ['required', 'timezone:all'], 'store_name' => ['required', 'max:160'], 'password' => ['required', Password::min(12)->letters()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data, $install) {
            $owner = User::create(['name' => $data['owner_name'], 'email' => $data['email'], 'password' => $data['password']]);
            $install->create($owner, $data);
        });
        $this->info('Negocio y propietario configurados. No existe alta de otras empresas en esta instalación.');

        return self::SUCCESS;
    }
}
