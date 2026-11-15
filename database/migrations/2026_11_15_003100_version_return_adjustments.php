<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_adjustments', function (Blueprint $table) {
            $table->unique(['id', 'sales_return_id'], 'uq_adjustment_receipt_pair');
        });
        Schema::create('return_adjustment_heads', function (Blueprint $table) {
            $table->foreignId('sales_return_id')->primary()->constrained('sales_returns');
            $table->unsignedBigInteger('active_adjustment_id')->nullable()->unique();
            $table->foreign(['active_adjustment_id', 'sales_return_id'], 'fk_adjustment_head_context')->references(['id', 'sales_return_id'])->on('return_adjustments');
        });
        Schema::create('return_adjustment_revision_items', function (Blueprint $table) {
            $table->foreignId('adjustment_id')->constrained('return_adjustments');
            $table->foreignId('sales_return_item_id')->constrained('sales_return_items');
            $table->foreignId('invoice_item_id')->constrained('invoice_items');
            $table->decimal('quantity', 19, 4);
            $table->decimal('credit_amount', 19, 4);
            $table->primary(['adjustment_id', 'sales_return_item_id']);
        });
        Schema::create('return_adjustment_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->unique()->constrained('return_adjustments');
            $table->decimal('amount', 19, 4);
            $table->string('reason', 255);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('actor_name', 255);
            $table->json('snapshot');
            $table->char('data_hash', 64);
            $table->dateTime('created_at', 6);
        });
        // Copy projections, never replace historical adjustments, lines or PDFs.
        DB::table('return_adjustment_heads')->insertUsing(['sales_return_id', 'active_adjustment_id'], DB::table('return_adjustments')->select('sales_return_id', 'id'));
        $columns = ['adjustment_id', 'sales_return_item_id', 'invoice_item_id', 'quantity', 'credit_amount'];
        DB::table('return_adjustment_revision_items')->insertUsing($columns, DB::table('return_adjustment_items')->select($columns));
        SchemaChecks::add('return_adjustment_revision_items', ['chk_revision_item_amount' => 'quantity > 0 AND credit_amount >= 0']);
        SchemaChecks::add('return_adjustment_reversals', ['chk_adjustment_reversal_amount' => 'amount >= 0']);
        // Only the obsolete one-version index is removed; every source row remains.
        Schema::table('return_adjustments', fn (Blueprint $table) => $table->dropUnique('return_adjustments_sales_return_id_unique'));
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions[] = 'adjustments.reverse';
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Adjustment revisions and compensations retain financial history. Use a reviewed forward migration.');
    }
};
