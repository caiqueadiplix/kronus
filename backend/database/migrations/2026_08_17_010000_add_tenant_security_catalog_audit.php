<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'public_name')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->string('public_name')->nullable());
        }
        if (! Schema::hasColumn('tenants', 'timezone')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->string('timezone', 64)->default('America/Sao_Paulo'));
        }
        if (! Schema::hasColumn('tenants', 'status')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->string('status', 20)->default('active'));
        }

        $productColumns = [
            'sku' => fn (Blueprint $table) => $table->string('sku', 64)->default(''),
            'barcode' => fn (Blueprint $table) => $table->string('barcode', 64)->default(''),
            'image_url' => fn (Blueprint $table) => $table->text('image_url')->default(''),
            'cost_price_cents' => fn (Blueprint $table) => $table->unsignedInteger('cost_price_cents')->default(0),
            'prep_time_minutes' => fn (Blueprint $table) => $table->unsignedSmallInteger('prep_time_minutes')->default(0),
            'active_pdv' => fn (Blueprint $table) => $table->boolean('active_pdv')->default(true),
            'active_delivery' => fn (Blueprint $table) => $table->boolean('active_delivery')->default(true),
            'active_site' => fn (Blueprint $table) => $table->boolean('active_site')->default(true),
            'allow_notes' => fn (Blueprint $table) => $table->boolean('allow_notes')->default(true),
            'stock_enabled' => fn (Blueprint $table) => $table->boolean('stock_enabled')->default(false),
            'stock_quantity' => fn (Blueprint $table) => $table->integer('stock_quantity')->default(0),
            'stock_minimum' => fn (Blueprint $table) => $table->integer('stock_minimum')->default(0),
            'position' => fn (Blueprint $table) => $table->unsignedInteger('position')->default(0),
            'options_json' => fn (Blueprint $table) => $table->text('options_json')->default('[]'),
        ];
        foreach ($productColumns as $column => $definition) {
            if (! Schema::hasColumn('products', $column)) {
                Schema::table('products', fn (Blueprint $table) => $definition($table));
            }
        }

        if (! Schema::hasTable('tenant_users')) {
            Schema::create('tenant_users', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('role', 32)->default('operator');
                $table->text('permissions_json')->default('{}');
                $table->timestamps();
                $table->unique(['tenant_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('action', 80)->index();
                $table->string('entity_type', 80)->index();
                $table->string('entity_id', 80)->nullable();
                $table->text('before_json')->default('{}');
                $table->text('after_json')->default('{}');
                $table->text('metadata_json')->default('{}');
                $table->string('ip_address', 45)->default('');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Evolutiva: não remove dados de operação em rollback.
    }
};
