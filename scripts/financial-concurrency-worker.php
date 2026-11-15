<?php

use App\Domain\Business\StoreContext;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/import-test-connection.php';
require __DIR__.'/financial-concurrency-support.php';

try {
    FinancialConcurrencyOperations::check(count($argv) === 3, 'Worker requires its run tag and job name.');
    [$script, $tag, $job] = $argv;
    $spec = FinancialConcurrencyOperations::loadJob($tag, $job);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 40');
    $context = app(StoreContext::class);
    $context->useStore(Store::findOrFail($spec['store_id']));
    $actor = User::findOrFail($spec['actor_id']);
    $payload = FinancialConcurrencyOperations::review($spec, $context);
    $directory = FinancialConcurrencyOperations::directory($tag);
    $ready = ['connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'pid' => getmypid(), 'operation' => $spec['operation'], 'actor_id' => $actor->id, 'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
    FinancialConcurrencyOperations::writeJson($directory.'/'.$job.'.ready.tmp', $ready);
    FinancialConcurrencyOperations::check(rename($directory.'/'.$job.'.ready.tmp', $directory.'/'.$job.'.ready.json'), 'Could not publish ready barrier.');
    $start = microtime(true);
    try {
        $result = FinancialConcurrencyOperations::execute($spec, $payload, $context, $actor);
        $outcome = ['status' => 200, 'replay' => (bool) ($result['replay'] ?? false), 'entity_id' => $result['reversal_id'] ?? $result['credit_application_id'] ?? $result['refund_id'] ?? $result['order']['id']];
    } catch (HttpExceptionInterface $e) {
        $outcome = ['status' => $e->getStatusCode(), 'replay' => false];
    } catch (ValidationException $e) {
        $outcome = ['status' => 422, 'replay' => false];
    }
    $outcome['elapsed_ms'] = (int) round((microtime(true) - $start) * 1000);
    $context->clear();
    echo 'RESULT:'.json_encode($outcome, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    echo 'RESULT:'.json_encode(['status' => 500, 'exception' => get_class($e), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
