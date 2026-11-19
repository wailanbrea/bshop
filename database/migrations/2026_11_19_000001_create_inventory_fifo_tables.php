<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_cost_lots')) {
            Schema::create('inventory_cost_lots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('variant_id');
                $table->unsignedBigInteger('source_movement_id')->nullable();
                $table->decimal('unit_cost', 19, 4)->nullable();
                $table->decimal('initial_quantity', 19, 4);
                $table->decimal('remaining_quantity', 19, 4);
                $table->timestamp('received_at')->useCurrent();
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->index(['warehouse_id', 'variant_id', 'remaining_quantity', 'received_at', 'id'], 'idx_fifo_lots_lookup');
                $table->foreign('warehouse_id', 'fk_fifo_lot_warehouse')->references('id')->on('warehouses');
                $table->foreign('variant_id', 'fk_fifo_lot_variant')->references('id')->on('product_variants');
                $table->foreign('source_movement_id', 'fk_fifo_lot_movement')->references('id')->on('inventory_movements');
            });
        }

        if (! Schema::hasTable('inventory_lot_allocations')) {
            Schema::create('inventory_lot_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lot_id')->nullable();
                $table->unsignedBigInteger('movement_id');
                $table->unsignedBigInteger('order_item_id')->nullable();
                $table->decimal('quantity', 19, 4);
                $table->decimal('unit_cost', 19, 4)->nullable();
                $table->decimal('total_cost', 19, 4)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('lot_id', 'idx_fifo_alloc_lot');
                $table->index('movement_id', 'idx_fifo_alloc_movement');
                $table->index('order_item_id', 'idx_fifo_alloc_order_item');
                $table->foreign('lot_id', 'fk_fifo_alloc_lot')->references('id')->on('inventory_cost_lots');
                $table->foreign('movement_id', 'fk_fifo_alloc_movement')->references('id')->on('inventory_movements');
            });
        }

        // Idempotent backfill: seed 1 initial lot for every existing positive stock balance without lots.
        if (Schema::hasTable('stock_balances') && Schema::hasTable('product_variants')) {
            $now = now();
            $balances = DB::table('stock_balances as sb')
                ->join('product_variants as pv', 'pv.id', '=', 'sb.variant_id')
                ->where('sb.on_hand', '>', 0)
                ->select('sb.warehouse_id', 'sb.variant_id', 'sb.on_hand', 'pv.cost_price')
                ->get();

            foreach ($balances as $row) {
                $exists = DB::table('inventory_cost_lots')
                    ->where('warehouse_id', $row->warehouse_id)
                    ->where('variant_id', $row->variant_id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $qty = bcadd((string) $row->on_hand, '0', 4);
                if (bccomp($qty, '0', 4) <= 0) {
                    continue;
                }
                DB::table('inventory_cost_lots')->insert([
                    'warehouse_id' => $row->warehouse_id,
                    'variant_id' => $row->variant_id,
                    'source_movement_id' => null,
                    'unit_cost' => $row->cost_price !== null ? bcadd((string) $row->cost_price, '0', 4) : null,
                    'initial_quantity' => $qty,
                    'remaining_quantity' => $qty,
                    'received_at' => $now,
                    'note' => 'Saldo inicial migrado a FIFO',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lot_allocations');
        Schema::dropIfExists('inventory_cost_lots');
    }
};
