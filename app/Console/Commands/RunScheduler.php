<?php

namespace App\Console\Commands;

use App\Domain\Operations\SchedulerMonitor;
use Illuminate\Console\Command;

class RunScheduler extends Command
{
    protected $signature = 'bshop:scheduler {--run : Execute due tasks; otherwise only inspect status}';

    protected $description = 'Run or inspect the local scheduler with persistent results and overlap protection';

    public function handle(SchedulerMonitor $monitor): int
    {
        if (! $this->option('run')) {
            $state = $monitor->status();
            $this->line(json_encode($state, JSON_THROW_ON_ERROR));

            return in_array($state['health'], ['ok', 'running'], true) ? self::SUCCESS : self::FAILURE;
        }
        $directory = $monitor->directory();
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            $this->error('Scheduler directory unavailable.');

            return self::FAILURE;
        }
        $lock = fopen($directory.'/scheduler.lock', 'c');
        if ($lock === false) {
            $this->error('Scheduler lock unavailable.');

            return self::FAILURE;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->line('{"status":"already_running"}');

            return self::SUCCESS;
        }
        try {
            $monitor->begin();
            try {
                $exit = $this->call('schedule:run');
            } catch (\Throwable $error) {
                report($error);
                $exit = self::FAILURE;
            }
            $result = $monitor->finish($exit);
            $this->line(json_encode($monitor->status(), JSON_THROW_ON_ERROR));

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
