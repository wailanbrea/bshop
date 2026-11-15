<?php

use App\Domain\Business\StoreContext;
use App\Domain\Sales\SalesService;
use App\Domain\Sales\WeeklyAccounts;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/financial-concurrency-support.php';

/** Restricted CLI protocol for the isolated weekly-account race suite. */
final class WeeklyConcurrencyOperations
{
    public const OPERATIONS = ['create', 'attach', 'detach', 'close', 'cancel', 'payment', 'invoice'];

    private array $workers = [];

    public static function directory(string $tag): string
    {
        FinancialConcurrencyOperations::check((bool) preg_match('/^[A-F0-9]{12}$/D', $tag), 'Invalid weekly QA tag.');
        $root = realpath(base_path('.runtime'));
        FinancialConcurrencyOperations::check($root !== false, 'Project runtime must already exist.');

        return $root.DIRECTORY_SEPARATOR.'weekly-race-'.$tag;
    }

    public static function loadJob(string $tag, string $job): array
    {
        $check = FinancialConcurrencyOperations::check(...);
        $check((bool) preg_match('/^[a-z0-9-]{3,100}$/D', $job), 'Invalid weekly job name.');
        $directory = realpath(self::directory($tag));
        $check($directory !== false && dirname($directory) === realpath(base_path('.runtime')), 'Weekly job directory escaped runtime.');
        $path = realpath($directory.'/'.$job.'.json');
        $check($path !== false && dirname($path) === $directory, 'Weekly job escaped its directory.');
        $s = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $check(($s['tag'] ?? null) === $tag && in_array($s['operation'] ?? null, self::OPERATIONS, true), 'Invalid weekly operation.');
        foreach (['store_id', 'product_id', 'variant_id', 'actor_id', 'customer_id'] as $field) {
            $check(is_int($s[$field] ?? null) && $s[$field] > 0, 'Invalid weekly entity: '.$field);
        }
        foreach (['store_id', 'product_id', 'variant_id'] as $field) {
            $check(($manifest[$field] ?? null) === $s[$field], 'Weekly manifest mismatch.');
        }
        $check(in_array($s['actor_id'], [$manifest['owner_id'], $manifest['actor_id']], true) && Str::isUuid($s['request_key'] ?? ''), 'Invalid weekly actor/UUID.');
        $store = Store::findOrFail($s['store_id']);
        $actor = User::findOrFail($s['actor_id']);
        $check($store->code === 'QA-WEEK-'.$tag && $store->is_active && $actor->is_active, 'Weekly fixtures are inactive or foreign.');
        $check(DB::table('store_user')->where('store_id', $store->id)->where('user_id', $actor->id)->exists(), 'Actor has no weekly QA store access.');
        $check(str_contains(Customer::findOrFail($s['customer_id'])->full_name, $tag), 'Customer is not a run fixture.');
        if ($s['operation'] !== 'create') {
            $check(is_int($s['account_id'] ?? null) && $s['account_id'] > 0, 'Invalid weekly account.');
            $check(DB::table('weekly_accounts')->where('id', $s['account_id'])->where('store_id', $store->id)->where('customer_id', $s['customer_id'])->exists(), 'Account is outside QA context.');
        } else {
            $check(is_string($s['period_start'] ?? null) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s['period_start']), 'Invalid QA period.');
        }
        if (in_array($s['operation'], ['attach', 'detach', 'payment', 'invoice'], true)) {
            $check(is_int($s['order_id'] ?? null) && $s['order_id'] > 0, 'Invalid weekly order.');
            $order = SalesOrder::where('store_id', $store->id)->where('customer_id', $s['customer_id'])->findOrFail($s['order_id']);
            $check($order->items()->count() === 1 && (int) $order->items()->firstOrFail()->variant_id === $s['variant_id'], 'Order is not a run-product fixture.');
        }
        $check((int) DB::table('product_variants')->where('id', $s['variant_id'])->value('product_id') === $s['product_id'], 'Weekly product mismatch.');
        if ($s['operation'] === 'payment') {
            $check(is_string($s['amount'] ?? null) && (bool) preg_match('/^\d{1,15}(?:\.\d{1,4})?$/D', $s['amount']), 'QA money must be an exact decimal string.');
        }

        return $s;
    }

    public static function review(array $s, StoreContext $context): array
    {
        $payload = ['request_key' => $s['request_key']];
        if ($s['operation'] === 'create') {
            return $payload + ['customer_id' => $s['customer_id'], 'period_start' => $s['period_start']];
        }
        if (in_array($s['operation'], ['payment', 'invoice'], true)) {
            $order = SalesOrder::where('store_id', $s['store_id'])->findOrFail($s['order_id']);
            $payload['version'] = $order->lock_version;
            if ($s['operation'] === 'payment') {
                $payload += ['amount' => $s['amount'], 'payment_method' => 'cash'];
            }

            return $payload;
        }
        $service = app(WeeklyAccounts::class);
        $review = $service->present($service->find($context, $s['account_id']));
        $payload['version'] = $review['lock_version'];
        if ($s['operation'] === 'close') {
            $payload['review_hash'] = $review['review_hash'];
        }
        if (in_array($s['operation'], ['attach', 'detach'], true)) {
            $payload['order_id'] = $s['order_id'];
        }

        return $payload;
    }

    public static function execute(array $s, array $payload, StoreContext $context, User $actor): array
    {
        $service = app(WeeklyAccounts::class);

        return match ($s['operation']) {
            'create' => $service->create($context, $actor, $payload),
            'attach', 'detach' => $service->membership($context, $s['account_id'], $s['operation'], $actor, $payload),
            'close' => $service->close($context, $s['account_id'], $actor, $payload),
            'cancel' => $service->cancel($context, $s['account_id'], $actor, $payload),
            'payment', 'invoice' => app(SalesService::class)->change($context, $s['order_id'], $s['operation'], $payload, $actor),
        };
    }

    private function running(array &$worker): bool
    {
        $state = proc_get_status($worker['process']);
        if (! $state['running'] && $state['exitcode'] >= 0) {
            $worker['exit_code'] = $state['exitcode'];
        }

        return $state['running'];
    }

    private function waitFor(callable $condition, string $message, int $seconds = 20): mixed
    {
        $deadline = microtime(true) + $seconds;
        do {
            $result = $condition();
            if ($result) {
                return $result;
            }
            FinancialConcurrencyOperations::check(microtime(true) <= $deadline, $message);
            usleep(25000);
        } while (true);
    }

    private function ready(string $directory): array
    {
        clearstatcache();
        $ready = [];
        foreach ($this->workers as &$worker) {
            $path = $directory.'/'.$worker['job'].'.ready.json';
            if (! is_file($path)) {
                FinancialConcurrencyOperations::check($this->running($worker), 'Worker failed before review; inspect '.$worker['out']);

                return [];
            }
            $ready[] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        }

        return $ready;
    }

    public static function blockedSessions(array $connections): array
    {
        $observed = [];
        foreach (DB::select('SHOW FULL PROCESSLIST') as $row) {
            $r = array_change_key_case((array) $row, CASE_LOWER);
            if (! in_array((int) $r['id'], $connections, true) || $r['db'] !== 'bshop_testing_single') {
                continue;
            }
            $sql = (string) ($r['info'] ?? '');
            if (preg_match('/\bfrom\s+`?(orders|products|weekly_accounts|weekly_cycle_locks|idempotency_requests)`?\b.*\bfor\s+update\b/is', $sql, $match)
                || preg_match('/\binsert\s+(?:ignore\s+)?into\s+`?(weekly_cycle_locks|idempotency_requests)`?\b/is', $sql, $match)) {
                $observed[] = ['connection_id' => (int) $r['id'], 'barrier' => $match[1], 'state' => $r['state']];
            }
        }

        return $observed;
    }

    /** Caller holds a shared domain barrier; release it only after proving SQL overlap. */
    public function race(string $tag, string $case, array $specs, bool $queueFirst, bool $samePayload): array
    {
        $check = FinancialConcurrencyOperations::check(...);
        $directory = self::directory($tag);
        $check(DB::transactionLevel() === 1 && ! $this->workers, 'Race requires one parent barrier transaction.');
        $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        try {
            foreach ($specs as $slot => $s) {
                $job = $case.'-s'.$slot;
                FinancialConcurrencyOperations::writeJson($directory.'/'.$job.'.json', $s);
                $out = $directory.'/'.$job.'.out';
                $err = $directory.'/'.$job.'.err';
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__.'/weekly-concurrency-worker.php', $tag, $job], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                $check(is_resource($process), 'Could not start weekly worker.');
                $this->workers[] = ['job' => $job, 'out' => $out, 'process' => $process, 'exit_code' => null];
                if ($slot === 0 && $queueFirst) {
                    $ready = $this->waitFor(fn () => $this->ready($directory), 'First worker review timeout.');
                    $this->waitFor(fn () => self::blockedSessions(array_column($ready, 'connection_id')), 'First worker SQL barrier timeout.');
                }
            }
            $ready = $this->waitFor(fn () => $this->ready($directory), 'Weekly worker review timeout.');
            $connections = array_column($ready, 'connection_id');
            $check(count(array_unique($connections)) === count($specs) && count(array_unique(array_column($ready, 'pid'))) === count($specs), 'Weekly workers are not independent.');
            if ($samePayload) {
                $check(count(array_unique(array_column($ready, 'payload_hash'))) === 1, 'Replay payloads differ.');
            }
            $observed = $this->waitFor(function () use ($connections, $specs) {
                $rows = self::blockedSessions($connections);

                return count($rows) === count($specs) ? $rows : [];
            }, 'Simultaneous weekly SQL waiters were not observed.');
            FinancialConcurrencyOperations::writeJson($directory.'/'.$case.'.overlap.json', ['parent_connection_id' => $parent, 'workers' => $ready, 'simultaneous_waiters' => $observed]);
        } finally {
            DB::rollBack();
        }
        $results = [];
        foreach ($this->workers as &$worker) {
            $this->waitFor(fn () => ! $this->running($worker), 'Weekly worker completion timeout.', 45);
            $closed = proc_close($worker['process']);
            $worker['process'] = null;
            $code = $closed >= 0 ? $closed : $worker['exit_code'];
            $result = FinancialConcurrencyOperations::parseWorkerResult(file_get_contents($worker['out']));
            $check($code === 0 && in_array($result['status'], [200, 409], true), 'Unexpected worker failure; inspect '.$worker['out']);
            $results[] = $result;
        }
        unset($worker);
        $this->workers = [];

        return ['results' => $results, 'simultaneous_waiters' => $observed];
    }

    public function stop(): void
    {
        foreach ($this->workers as &$worker) {
            if (! is_resource($worker['process'])) {
                continue;
            }
            if ($this->running($worker)) {
                proc_terminate($worker['process']);
            }
            $this->waitFor(fn () => ! $this->running($worker), 'Owned weekly worker did not stop; fixtures need manual inspection.', 5);
            proc_close($worker['process']);
            $worker['process'] = null;
        }
        $this->workers = [];
    }

    public static function snapshot(): array
    {
        return DB::transaction(function () {
            $saved = [];
            foreach (DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='bshop_testing_single' AND TABLE_TYPE='BASE TABLE'") as $row) {
                $name = $row->TABLE_NAME;
                FinancialConcurrencyOperations::check((bool) preg_match('/^[a-z][a-z0-9_]*$/D', $name), 'Unexpected QA table name.');
                $saved[$name] = ['schema' => ((array) DB::selectOne('SHOW CREATE TABLE `'.$name.'`'))['Create Table'], 'rows' => DB::table($name)->get()->map(fn ($r) => (array) $r)->all()];
            }

            return $saved;
        });
    }

    public static function verifyPreserved(array $snapshot): void
    {
        foreach ($snapshot as $table => $saved) {
            if (! $saved['rows']) {
                continue;
            }
            $counts = [];
            foreach (DB::table($table)->select(array_keys($saved['rows'][0]))->get() as $row) {
                $key = hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR));
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            foreach ($saved['rows'] as $row) {
                $key = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                FinancialConcurrencyOperations::check(($counts[$key] ?? 0) > 0, 'Preexisting row changed or disappeared: '.$table);
                $counts[$key]--;
            }
        }
    }
}
