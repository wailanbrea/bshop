<?php

use App\Domain\Business\AuditTrailService;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Enforces local SQLite identity and verifies an integral backup before any update.
require __DIR__.'/backup-local.php';
echo "Owner-confirmed currency configuration: local SQLite demo only; USD / US$.\n";
DB::transaction(function () {
    $business = BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
        if (Schema::hasColumn($table->name, 'currency') && DB::table($table->name)->where('currency', '!=', 'USD')->exists()) {
            throw new RuntimeException('Existing non-USD documents in '.$table->name.'; no automatic conversion is allowed.');
        }
    }
    $before = $business->attributesToArray();
    $business->update(['default_currency' => 'USD', 'currency_decimals' => 2]);
    $owner = User::where('email', 'demo@bshop.test')->where('is_active', true)->firstOrFail();
    app(AuditTrailService::class)->record('business.currency.configured', $business, before: $before, after: ['default_currency' => 'USD', 'currency_decimals' => 2, 'reason' => 'Explicit owner instruction: the system must use dollars.'], actor: $owner);
});
echo "Verified: business currency USD; 2 display decimals; historical document amounts/currencies preserved.\n";
