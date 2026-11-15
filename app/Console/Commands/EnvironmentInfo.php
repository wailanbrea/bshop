<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnvironmentInfo extends Command
{
    protected $signature = 'bshop:environment';

    protected $description = 'Show the effective database destination without credentials or queries.';

    public function handle(): int
    {
        $connection = DB::connection();
        $this->table(['Environment', 'Connection', 'Driver', 'Host', 'Port', 'Database'], [[
            app()->environment(), DB::getDefaultConnection(), $connection->getDriverName(),
            $connection->getConfig('host') ?? '(local file)', $connection->getConfig('port') ?? '—',
            $connection->getDatabaseName(),
        ]]);

        return self::SUCCESS;
    }
}
