<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salon_sectors')) {
            Schema::create('salon_sectors', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('name', 60);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['tenant_id', 'name']);
            });
        }

        $tableColumns = [
            'sector_id' => fn (Blueprint $table) => $table->unsignedBigInteger('sector_id')->nullable()->index(),
            'seats' => fn (Blueprint $table) => $table->unsignedSmallInteger('seats')->default(4),
            'position_x' => fn (Blueprint $table) => $table->unsignedSmallInteger('position_x')->default(1),
            'position_y' => fn (Blueprint $table) => $table->unsignedSmallInteger('position_y')->default(1),
            'shape' => fn (Blueprint $table) => $table->string('shape', 12)->default('square'),
            'qr_token' => fn (Blueprint $table) => $table->string('qr_token', 80)->nullable()->index(),
        ];
        foreach ($tableColumns as $column => $definition) {
            if (! Schema::hasColumn('restaurant_tables', $column)) Schema::table('restaurant_tables', $definition);
        }

        if (! Schema::hasTable('salon_movements')) {
            Schema::create('salon_movements', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('action', 40);
                $table->string('source_kind', 16)->default('');
                $table->unsignedInteger('source_number')->nullable();
                $table->string('target_kind', 16)->default('');
                $table->unsignedInteger('target_number')->nullable();
                $table->string('session_id', 80)->default('');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->text('metadata_json')->default('{}');
                $table->timestamps();
            });
        }

        foreach (['waiter_id' => fn (Blueprint $table) => $table->unsignedBigInteger('waiter_id')->nullable()->index(), 'service_fee_cents' => fn (Blueprint $table) => $table->unsignedInteger('service_fee_cents')->default(0)] as $column => $definition) {
            if (! Schema::hasColumn('orders', $column)) Schema::table('orders', $definition);
        }

        if (Schema::hasColumn('restaurant_tables', 'qr_token')) {
            $tables = \Illuminate\Support\Facades\DB::table('restaurant_tables')->whereNull('qr_token')->get();
            foreach ($tables as $table) {
                \Illuminate\Support\Facades\DB::table('restaurant_tables')->where('id', $table->id)->update(['qr_token' => (string) Str::uuid()]);
            }
        }
    }

    public function down(): void
    {
        // Migração evolutiva: não remove dados operacionais em rollback.
    }
};
