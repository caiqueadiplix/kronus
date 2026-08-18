<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'auto_print_orders')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->boolean('auto_print_orders')->default(false));
        }

        $addressColumns = [
            'postal_code' => fn (Blueprint $table) => $table->string('postal_code', 8)->default(''),
            'street' => fn (Blueprint $table) => $table->string('street')->default(''),
            'address_number' => fn (Blueprint $table) => $table->string('address_number', 20)->default(''),
            'address_complement' => fn (Blueprint $table) => $table->string('address_complement')->default(''),
            'neighborhood' => fn (Blueprint $table) => $table->string('neighborhood')->default(''),
            'city' => fn (Blueprint $table) => $table->string('city')->default(''),
            'state' => fn (Blueprint $table) => $table->string('state', 2)->default(''),
        ];
        foreach ($addressColumns as $column => $definition) {
            if (! Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $table) => $definition($table));
            }
        }

        if (! Schema::hasTable('operator_notifications')) {
            Schema::create('operator_notifications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('type')->default('human_handoff');
                $table->string('source')->default('whatsapp');
                $table->string('external_id')->default('')->index();
                $table->string('title');
                $table->text('message')->default('');
                $table->string('customer')->default('');
                $table->string('phone')->default('');
                $table->string('status')->default('unread')->index();
                $table->text('metadata')->default('{}');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Migração evolutiva: não remove dados operacionais em rollback.
    }
};
