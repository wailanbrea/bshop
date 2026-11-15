<?php

namespace App\Console\Commands;

use App\Domain\Inventory\InventoryReconciliation;
use App\Models\Warehouse;
use Illuminate\Console\Command;

class ReconcileInventory extends Command
{
    protected $signature = 'bshop:inventory-reconcile {--warehouse= : Inspect a single warehouse} {--json : Print structured results}';

    protected $description = 'Read-only comparison of stock balances, active reservations and inventory movements';

    public function handle(InventoryReconciliation $service): int
    {
        $id = $this->option('warehouse');
        if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('warehouse must be a positive integer.');

            return self::INVALID;
        }
        $warehouses = Warehouse::query()->when($id !== null, fn ($q) => $q->whereKey((int) $id))->orderBy('id')->get();
        if ($warehouses->isEmpty()) {
            $this->error('No warehouses found.');

            return self::INVALID;
        }
        $results = [];
        $hasDifferences = false;
        foreach ($warehouses as $warehouse) {
            $report = $service->inspect($warehouse->store_id, $warehouse->id);
            $different = array_values(array_filter($report['data'], fn ($row) => count($row['differences']) > 0));
            $hasDifferences = $hasDifferences || count($different) > 0;
            $results[] = ['store_id' => $warehouse->store_id, 'warehouse_id' => $warehouse->id, 'summary' => $report['summary'],
                'generated_at' => $report['generated_at'], 'data' => array_slice($different, 0, 100), 'truncated' => count($different) > 100];
        }
        if ($this->option('json')) {
            $this->line(json_encode(['warehouses' => $results, 'read_only' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($results as $result) {
                $this->line('Warehouse '.$result['warehouse_id'].': '.$result['summary']['checked'].' variants checked, '.$result['summary']['with_differences'].' with differences. No data changed.');
            }
        }

        return $hasDifferences ? self::FAILURE : self::SUCCESS;
    }
}
