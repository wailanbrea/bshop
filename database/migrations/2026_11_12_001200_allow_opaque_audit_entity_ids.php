<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->string('entity_id', 64)->change());
    }

    public function down(): void
    {
        foreach (DB::table('audit_logs')->orderBy('id')->lazyById() as $record) {
            if (! ctype_digit((string) $record->entity_id) || bccomp((string) $record->entity_id, '18446744073709551615') > 0) {
                throw new RuntimeException('Audit IDs include opaque identifiers. Refusing a lossy rollback.');
            }
        }
        Schema::table('audit_logs', fn (Blueprint $table) => $table->unsignedBigInteger('entity_id')->change());
    }
};
