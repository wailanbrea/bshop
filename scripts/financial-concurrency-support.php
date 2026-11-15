<?php

use App\Domain\Business\StoreContext;
use App\Domain\Sales\CreditApplicationReversals;
use App\Domain\Sales\CreditApplications;
use App\Domain\Sales\RefundReversals;
use App\Domain\Sales\ReturnAdjustmentReversals;
use App\Domain\Sales\ReturnReceiptReversals;
use App\Domain\Sales\ReturnSettlements;
use App\Domain\Sales\SalesService;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** CLI-only protocol. Never accepts a database, executable or PHP class from a job. */
final class FinancialConcurrencyOperations
{
    public const OPERATIONS = ['apply', 'refund', 'undo_adjustment', 'undo_credit', 'undo_refund', 'undo_receipt', 'payment'];

    public static function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    public static function directory(string $tag): string
    {
        self::check((bool) preg_match('/^[A-F0-9]{12}$/D', $tag), 'Invalid QA run tag.');
        $root = realpath(base_path('.runtime'));
        self::check($root !== false, 'The project runtime directory must exist.');

        return $root.DIRECTORY_SEPARATOR.'financial-race-'.$tag;
    }

    public static function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        self::check(! file_exists($path) && file_put_contents($path, $json, LOCK_EX) === strlen($json), 'QA artifact could not be written.');
    }

    public static function parseWorkerResult(string $output): array
    {
        preg_match_all('/^RESULT:(\{[^\r\n]*\})\r?$/m', $output, $matches);
        self::check(count($matches[1]) === 1, 'Worker must emit exactly one result.');
        $result = json_decode($matches[1][0], true, flags: JSON_THROW_ON_ERROR);
        self::check(is_array($result) && is_int($result['status'] ?? null), 'Worker result has an invalid status.');
        if (in_array($result['status'], [200, 409], true)) {
            self::check(is_bool($result['replay'] ?? null), 'Worker result lacks a replay flag.');
        }

        return $result;
    }

    public static function blockedSessions(array $connections, bool $allowIdempotency): array
    {
        $observed = [];
        foreach (DB::select('SHOW FULL PROCESSLIST') as $row) {
            $r = array_change_key_case((array) $row, CASE_LOWER);
            if (! in_array((int) $r['id'], $connections, true)) {
                continue;
            }
            $info = (string) ($r['info'] ?? '');
            $productWait = preg_match('/\bfrom\s+`?products`?\s.*\bfor\s+update\b/is', $info);
            $idempotencyWait = $allowIdempotency && str_contains(strtolower($info), 'idempotency_requests') && (str_contains(strtolower($info), 'insert') || str_contains(strtolower($info), 'for update'));
            if (($r['db'] ?? null) === 'bshop_testing_single' && ($productWait || $idempotencyWait)) {
                $observed[] = ['connection_id' => (int) $r['id'], 'barrier' => $productWait ? 'product' : 'idempotency', 'state' => $r['state']];
            }
        }

        return $observed;
    }

    public static function loadJob(string $tag, string $job): array
    {
        self::check((bool) preg_match('/^[a-z0-9-]{3,100}$/D', $job), 'Invalid worker job name.');
        $directory = realpath(self::directory($tag));
        $runtime = realpath(base_path('.runtime'));
        self::check($directory !== false && str_starts_with($directory, $runtime.DIRECTORY_SEPARATOR), 'Worker directory is outside the project runtime.');
        $path = realpath($directory.DIRECTORY_SEPARATOR.$job.'.json');
        self::check($path !== false && dirname($path) === $directory, 'Worker job escaped its run directory.');
        $spec = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::check(is_array($spec) && ($spec['tag'] ?? null) === $tag && in_array($spec['operation'] ?? null, self::OPERATIONS, true), 'Invalid worker specification.');
        self::check(Str::isUuid($spec['request_key'] ?? '') && is_string($spec['reason'] ?? null) && strlen($spec['reason']) <= 255, 'Invalid worker request identity.');
        foreach (['store_id', 'customer_id', 'product_id', 'variant_id', 'actor_id', 'source_id', 'order_id'] as $field) {
            self::check(is_int($spec[$field] ?? null) && $spec[$field] > 0, 'Invalid worker entity: '.$field);
        }
        $required = match ($spec['operation']) {
            'apply' => ['payment_id'],
            'refund' => ['payment_id', 'receipt_id'],
            'undo_adjustment' => ['receipt_id', 'adjustment_id'],
            'undo_credit' => ['application_id'],
            'undo_refund' => ['receipt_id', 'refund_id'],
            'undo_receipt' => ['receipt_id'],
            'payment' => [],
        };
        foreach ($required as $field) {
            self::check(is_int($spec[$field] ?? null) && $spec[$field] > 0, 'Invalid operation entity: '.$field);
        }
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['store_id', 'product_id', 'variant_id'] as $field) {
            self::check(($manifest[$field] ?? null) === $spec[$field], 'Worker differs from its run manifest.');
        }
        self::check(in_array($spec['actor_id'], [$manifest['owner_id'] ?? null, $manifest['actor_id'] ?? null], true), 'Actor is outside the run manifest.');
        self::check(is_string($spec['amount'] ?? null) && (bool) preg_match('/^\d{1,15}(?:\.\d{1,4})?$/D', $spec['amount']), 'Worker money must be a decimal string.');
        $store = Store::findOrFail($spec['store_id']);
        self::check($store->code === 'QA-FIN-'.$tag && $store->is_active, 'Worker store is not the active synthetic run store.');
        $actor = User::findOrFail($spec['actor_id']);
        self::check($actor->is_active && DB::table('store_user')->where('store_id', $store->id)->where('user_id', $actor->id)->exists(), 'Worker actor has no run-store access.');
        foreach (array_unique([$spec['source_id'], $spec['order_id']]) as $id) {
            $order = SalesOrder::where('store_id', $store->id)->where('customer_id', $spec['customer_id'])->findOrFail($id);
            self::check($order->items()->count() === 1 && (int) $order->items()->firstOrFail()->variant_id === $spec['variant_id'], 'Worker order is not a fixture for this product.');
        }
        self::check((int) DB::table('product_variants')->where('id', $spec['variant_id'])->value('product_id') === $spec['product_id'], 'Worker product mismatch.');

        return $spec;
    }

    public static function review(array $s, StoreContext $context): array
    {
        $service = app(SalesService::class);
        $r = match ($s['operation']) {
            'apply' => app(CreditApplications::class)->info($context, $s['order_id']),
            'refund' => app(ReturnSettlements::class)->info($context, $s['source_id'], $s['receipt_id'], []),
            'undo_adjustment' => app(ReturnAdjustmentReversals::class)->info($context, $s['source_id'], $s['receipt_id'], $s['adjustment_id']),
            'undo_credit' => app(CreditApplicationReversals::class)->info($context, $s['order_id'], $s['application_id']),
            'undo_refund' => app(RefundReversals::class)->info($context, $s['source_id'], $s['receipt_id'], $s['refund_id']),
            'undo_receipt' => app(ReturnReceiptReversals::class)->info($context, $s['source_id'], $s['receipt_id']),
            'payment' => $service->present(SalesOrder::where('store_id', $s['store_id'])->findOrFail($s['source_id'])),
        };
        $payload = ['request_key' => $s['request_key'], 'version' => $r['version'] ?? $r['lock_version'], 'reason' => $s['reason']];
        if (isset($r['review_hash'])) {
            $payload['review_hash'] = $r['review_hash'];
        }
        if ($s['operation'] === 'apply') {
            $payload += ['payment_id' => $s['payment_id'], 'amount' => $s['amount']];
        }
        if ($s['operation'] === 'refund') {
            $payload += ['payment_id' => $s['payment_id'], 'amount' => $s['amount'], 'method' => 'cash', 'external_reference' => null];
        }
        if ($s['operation'] === 'payment') {
            $payload += ['amount' => $s['amount'], 'payment_method' => 'cash'];
        }
        if ($s['operation'] === 'undo_refund') {
            $payload['confirmed_no_money'] = true;
        }

        return $payload;
    }

    public static function execute(array $s, array $payload, StoreContext $context, User $actor): array
    {
        return match ($s['operation']) {
            'apply' => app(CreditApplications::class)->apply($context, $s['order_id'], $actor, $payload),
            'refund' => app(ReturnSettlements::class)->refund($context, $s['source_id'], $s['receipt_id'], $actor, $payload),
            'undo_adjustment' => app(ReturnAdjustmentReversals::class)->reverse($context, $s['source_id'], $s['receipt_id'], $s['adjustment_id'], $actor, $payload),
            'undo_credit' => app(CreditApplicationReversals::class)->reverse($context, $s['order_id'], $s['application_id'], $actor, $payload),
            'undo_refund' => app(RefundReversals::class)->reverse($context, $s['source_id'], $s['receipt_id'], $s['refund_id'], $actor, $payload),
            'undo_receipt' => app(ReturnReceiptReversals::class)->reverse($context, $s['source_id'], $s['receipt_id'], $actor, $payload),
            'payment' => app(SalesService::class)->change($context, $s['source_id'], 'payment', $payload, $actor),
        };
    }
}
