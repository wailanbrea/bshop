<?php

namespace Tests\Feature;

use App\Domain\Operations\SchedulerMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('operations_qa');
        config(['operations.path' => Storage::disk('operations_qa')->path('')]);
        $this->app->make(Kernel::class)->bootstrap();
        $this->schedule = new Schedule('UTC');
        $this->app->instance(Schedule::class, $this->schedule);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:01:00', 'UTC'));
    }

    public function test_status_does_not_execute_jobs_or_create_heartbeat_and_detects_stale_runs(): void
    {
        $ran = false;
        $this->schedule->call(function () use (&$ran) {
            $ran = true;
        })->everyMinute();
        $this->artisan('bshop:scheduler')->expectsOutputToContain('"health":"never_run"')->assertExitCode(1);
        $this->assertFalse($ran);
        Storage::disk('operations_qa')->assertMissing('scheduler.json');
        $this->artisan('bshop:scheduler', ['--run' => true])->assertExitCode(0);
        $this->assertTrue($ran);
        $this->assertSame('ok', app(SchedulerMonitor::class)->status()['health']);
        $this->travel(6)->minutes();
        $this->artisan('bshop:scheduler')->expectsOutputToContain('"health":"stale"')->assertExitCode(1);
    }

    public function test_nested_failure_is_reported_and_persists_until_the_same_task_recovers(): void
    {
        $success = false;
        $event = $this->schedule->call(function () use (&$success) {
            return $success;
        })->name('qa-failure-with-sensitive-description')->everyMinute();
        $this->artisan('bshop:scheduler', ['--run' => true])->assertExitCode(1);
        $state = app(SchedulerMonitor::class)->status();
        $this->assertSame('failed', $state['health']);
        $this->assertSame(1, array_values($state['tasks'])[0]['exit_code']);
        $this->assertStringNotContainsString('sensitive-description', Storage::disk('operations_qa')->get('scheduler.json'));
        $allowed = false;
        $event->when(function () use (&$allowed) {
            return $allowed;
        });
        $this->artisan('bshop:scheduler', ['--run' => true])->assertExitCode(1);
        $this->assertSame('failed', app(SchedulerMonitor::class)->status()['health']);
        $allowed = true;
        $success = true;
        $this->artisan('bshop:scheduler', ['--run' => true])->assertExitCode(0);
        $this->assertSame('ok', app(SchedulerMonitor::class)->status()['health']);
    }

    public function test_another_process_lock_prevents_execution_and_does_not_overwrite_status(): void
    {
        $ran = false;
        $this->schedule->call(function () use (&$ran) {
            $ran = true;
        })->everyMinute();
        $lock = fopen(app(SchedulerMonitor::class)->directory().'/scheduler.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->artisan('bshop:scheduler', ['--run' => true])->expectsOutputToContain('"already_running"')->assertExitCode(0);
            $this->assertFalse($ran);
            Storage::disk('operations_qa')->assertMissing('scheduler.json');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_corrupt_state_is_rejected_without_running_jobs(): void
    {
        Storage::disk('operations_qa')->put('scheduler.json', '{invalid');
        $ran = false;
        $this->schedule->call(function () use (&$ran) {
            $ran = true;
        })->everyMinute();
        try {
            $this->artisan('bshop:scheduler', ['--run' => true])->run();
            $this->fail('Corrupt state must stop execution.');
        } catch (JsonException) {
            $this->assertFalse($ran);
            $this->assertSame('{invalid', Storage::disk('operations_qa')->get('scheduler.json'));
        }
    }
}
