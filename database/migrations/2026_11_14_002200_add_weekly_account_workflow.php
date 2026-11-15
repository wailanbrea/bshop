<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lock-only sentinel: avoid store/customer FK locks before the period scan.
        // The business operation separately validates both referenced identities.
        Schema::create('weekly_cycle_locks', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->primary(['store_id', 'customer_id']);
        });
        Schema::table('weekly_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->char('currency', 3)->nullable();
            $table->string('timezone', 80)->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->decimal('paid_at_close', 19, 4)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Weekly closing snapshots retain financial history; use a reviewed forward migration.');
    }
};
