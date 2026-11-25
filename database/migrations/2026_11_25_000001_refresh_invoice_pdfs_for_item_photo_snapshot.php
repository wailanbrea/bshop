<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mark cached invoice PDFs pending so they re-render cleanly with exact order_items.image_url_snapshot photos
        if (Schema::hasTable('invoice_documents')) {
            DB::table('invoice_documents')
                ->where('status', 'ready')
                ->update(['status' => 'pending']);
        }
    }

    public function down(): void
    {
        // Non-destructive cache invalidation; nothing to roll back.
    }
};
