<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table): void {
                $table->id();
                $table->text('name');
                $table->string('slug')->unique();
                $table->timestamp('created_at')->useCurrent();
                $table->boolean('auto_accept')->default(true);
            });
        }
        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->text('name');
                $table->integer('position')->default(0);
                $table->unique(['tenant_id', 'name']);
            });
        }
        if (! Schema::hasTable('commands')) {
            Schema::create('commands', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedInteger('number');
                $table->string('status')->default('free');
                $table->text('customer')->default('');
                $table->unique(['tenant_id', 'number']);
            });
        }
        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('category_id')->index();
                $table->text('name');
                $table->text('description')->default('');
                $table->unsignedInteger('price_cents');
                $table->boolean('active')->default(true);
            });
        }
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->text('customer');
                $table->text('phone')->default('');
                $table->string('channel');
                $table->string('type');
                $table->string('status');
                $table->text('address')->default('');
                $table->integer('fee_cents')->default(0);
                $table->unsignedInteger('table_number')->nullable();
                $table->unsignedInteger('command_number')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('accepted_at')->nullable();
                $table->string('payment_status')->default('pending');
                $table->text('driver_name')->default('');
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->string('payment_method')->default('');
                $table->integer('paid_amount_cents')->default(0);
                $table->timestamp('paid_at')->nullable();
                $table->integer('position')->default(0);
                $table->string('external_id')->default('');
            });
        }
        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->text('name');
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedInteger('unit_price_cents');
                $table->text('notes')->default('');
            });
        }
        if (! Schema::hasTable('order_events')) {
            Schema::create('order_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->text('event');
                $table->text('details')->default('');
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Evolutiva: não remove dados operacionais.
    }
};
