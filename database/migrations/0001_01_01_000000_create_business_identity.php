<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profile', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->default(1)->primary();
            $table->string('name', 160);
            $table->string('legal_name', 200)->nullable();
            $table->char('default_currency', 3);
            $table->unsignedTinyInteger('currency_decimals')->default(2);
            $table->string('timezone', 80);
            $table->string('locale', 12)->default('es_DO');
            $table->timestamps(6);
        });
        SchemaChecks::add('business_profile', ['chk_business_singleton' => 'id = 1', 'chk_business_decimals' => 'currency_decimals <= 4']);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('email_verified_at', 6)->nullable();
            $table->rememberToken();
            $table->timestamps(6);
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('label', 100);
            $table->json('permissions');
            $table->timestamps(6);
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained();
            $table->foreignId('role_id')->constrained();
            $table->primary(['user_id', 'role_id']);
        });
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('code', 32)->unique();
            $table->string('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->string('name', 160);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('default_store_id')->nullable()->virtualAs('CASE WHEN is_default = 1 AND is_active = 1 THEN store_id ELSE NULL END');
            $table->unique('default_store_id', 'uq_active_default_warehouse');
            $table->unique(['store_id', 'id']);
        });
        Schema::create('store_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->primary(['user_id', 'store_id']);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('event', 100);
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('occurred_at', 6);
            $table->index(['entity_type', 'entity_id']);
        });
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained();
            $table->foreignId('invited_by_user_id')->constrained('users');
            $table->string('email', 190);
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->dateTime('expires_at', 6);
            $table->dateTime('accepted_at', 6)->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users');
            $table->timestamps(6);
            $table->index(['email', 'status']);
        });
        Schema::create('invitation_store_access', function (Blueprint $table) {
            $table->foreignId('invitation_id')->constrained('team_invitations');
            $table->foreignId('store_id')->constrained();
            $table->primary(['invitation_id', 'store_id']);
        });
    }

    public function down(): void
    {
        foreach (['invitation_store_access', 'team_invitations', 'audit_logs', 'store_user', 'warehouses', 'stores', 'role_user', 'roles', 'sessions', 'password_reset_tokens', 'users', 'business_profile'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
