<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdjustmentRevisionMigrationTest extends TestCase
{
    public function test_existing_adjustments_and_lines_are_copied_without_changing_historical_rows(): void
    {
        // Dedicated in-memory legacy schema: no rollback/drop of a commercial database.
        Schema::create('users', fn (Blueprint $t) => $t->id());
        Schema::create('sales_returns', fn (Blueprint $t) => $t->id());
        Schema::create('sales_return_items', fn (Blueprint $t) => $t->id());
        Schema::create('invoice_items', fn (Blueprint $t) => $t->id());
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->json('permissions');
        });
        Schema::create('return_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_return_id')->unique()->constrained('sales_returns');
            $t->decimal('amount', 19, 4);
            $t->json('snapshot');
        });
        Schema::create('return_adjustment_items', function (Blueprint $t) {
            $t->foreignId('sales_return_item_id')->primary()->constrained('sales_return_items');
            $t->foreignId('adjustment_id')->constrained('return_adjustments');
            $t->foreignId('invoice_item_id')->constrained('invoice_items');
            $t->decimal('quantity', 19, 4);
            $t->decimal('credit_amount', 19, 4);
        });
        foreach (['sales_returns', 'sales_return_items', 'invoice_items'] as $table) {
            DB::table($table)->insert(['id' => 1]);
        }
        DB::table('roles')->insert(['code' => 'owner', 'permissions' => '["sales.view"]']);
        DB::table('return_adjustments')->insert(['id' => 1, 'sales_return_id' => 1, 'amount' => '1.2300', 'snapshot' => '{"original":true}']);
        DB::table('return_adjustment_items')->insert(['sales_return_item_id' => 1, 'adjustment_id' => 1, 'invoice_item_id' => 1, 'quantity' => '2.0000', 'credit_amount' => '1.2300']);
        $before = DB::table('return_adjustments')->get()->toJson();
        $lines = DB::table('return_adjustment_items')->get()->toJson();
        $migration = require database_path('migrations/2026_11_15_003100_version_return_adjustments.php');
        $migration->up();
        $this->assertSame($before, DB::table('return_adjustments')->get()->toJson());
        $this->assertSame($lines, DB::table('return_adjustment_items')->get()->toJson());
        $this->assertDatabaseHas('return_adjustment_heads', ['sales_return_id' => 1, 'active_adjustment_id' => 1]);
        $this->assertDatabaseHas('return_adjustment_revision_items', ['adjustment_id' => 1, 'sales_return_item_id' => 1, 'quantity' => '2', 'credit_amount' => '1.23']);
        $this->assertContains('adjustments.reverse', json_decode(DB::table('roles')->value('permissions'), true));
        DB::table('return_adjustments')->insert(['id' => 2, 'sales_return_id' => 1, 'amount' => '1', 'snapshot' => '{}']);
        $this->assertDatabaseCount('return_adjustments', 2);
        $this->assertSame(1, (int) DB::table('return_adjustment_heads')->value('active_adjustment_id'));
    }
}
