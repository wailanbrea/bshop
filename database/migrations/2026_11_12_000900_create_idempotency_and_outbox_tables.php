<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Single-business installation: no tenant/company scope or SaaS subscription tables.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_requests', function (Blueprint $table) {
            $table->id('id');
            $table->string('operation', 80);
            $table->string('request_key', 160);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('processing');
            $table->string('resource_type', 60)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->unique(['operation', 'request_key'], 'uq_ir_request');
        });
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id('id');
            $table->string('event_key', 160);
            $table->string('event_type', 100);
            $table->string('aggregate_type', 100);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('available_at', 6);
            $table->dateTime('processed_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->index(['status', 'available_at'], 'idx_outbox_dispatch');
            $table->unique(['event_key'], 'uq_outbox_event');
        });
        SchemaChecks::add('outbox_events', ['chk_outbox_status' => 'status IN (\'pending\',\'processing\',\'done\',\'failed\')']);
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('idempotency_requests');
    }
};
