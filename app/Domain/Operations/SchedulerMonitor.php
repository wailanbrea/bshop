<?php

namespace App\Domain\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use RuntimeException;

final class SchedulerMonitor
{
    private ?array $current = null;

    public function directory(): string
    {
        return config('operations.path', storage_path('app/private/operations'));
    }

    public function read(): array
    {
        $path = $this->directory().'/scheduler.json';
        if (! file_exists($path)) {
            return ['status' => 'never_run', 'tasks' => []];
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['schema'] ?? null) !== 1 || ! is_array($data['tasks'] ?? null)) {
            throw new RuntimeException('Invalid scheduler state; preserve the file for diagnosis.');
        }

        return $data;
    }

    public function status(): array
    {
        $data = $this->read();
        $timestamp = $data['finished_at'] ?? $data['started_at'] ?? null;
        $health = ! $timestamp ? 'never_run' : (CarbonImmutable::parse($timestamp)->lt(CarbonImmutable::now('UTC')->subMinutes(5)) ? 'stale' : $data['status']);
        if ($health !== 'stale' && ($data['status'] === 'failed' || collect($data['tasks'])->contains(fn ($t) => $t['status'] === 'failed'))) {
            $health = 'failed';
        }

        return [...$data, 'health' => $health];
    }

    public function begin(): void
    {
        $previous = $this->read();
        $this->current = ['schema' => 1, 'status' => 'running', 'started_at' => CarbonImmutable::now('UTC')->toIso8601String(), 'finished_at' => null, 'pid' => getmypid(), 'tasks' => $previous['tasks']];
        $this->persist();
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->task($event->task, (int) $event->task->exitCode);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->task($event->task, $event->task->exitCode ?: 1);
    }

    private function task(Event $task, int $exit): void
    {
        if ($this->current === null) {
            return;
        }
        // Store identifiers, never raw command lines or exception messages.
        $name = preg_match('/\bbshop:[a-z-]+\b/', $task->command ?? '', $match) ? $match[0] : 'task-'.substr(hash('sha256', $task->mutexName()), 0, 12);
        $this->current['tasks'][$name] = ['status' => $exit === 0 ? 'ok' : 'failed', 'exit_code' => $exit, 'finished_at' => CarbonImmutable::now('UTC')->toIso8601String()];
        $this->persist();
    }

    public function finish(int $exit): int
    {
        $failed = $exit !== 0 || collect($this->current['tasks'])->contains(fn ($t) => $t['status'] === 'failed');
        $this->current['status'] = $failed ? 'failed' : 'ok';
        $this->current['finished_at'] = CarbonImmutable::now('UTC')->toIso8601String();
        $this->persist();
        $this->current = null;

        return $failed ? 1 : 0;
    }

    private function persist(): void
    {
        $path = $this->directory().'/scheduler.json';
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($this->current, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || ! rename($temporary, $path)) {
            throw new RuntimeException('Could not save scheduler state.');
        }
    }
}
