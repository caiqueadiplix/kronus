<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'service_fee_enabled')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->boolean('service_fee_enabled')->default(false));
        }
        if (! Schema::hasColumn('tenants', 'service_fee_percent')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->decimal('service_fee_percent', 5, 2)->default(10));
        }
        if (! Schema::hasColumn('tenant_users', 'active')) {
            Schema::table('tenant_users', fn (Blueprint $table) => $table->boolean('active')->default(true));
        }
        if (! Schema::hasColumn('tenant_users', 'phone')) {
            Schema::table('tenant_users', fn (Blueprint $table) => $table->string('phone', 24)->default(''));
        }
    }

    public function down(): void
    {
        // Migração evolutiva: não remove dados de equipe ou configuração em rollback.
    }
};
