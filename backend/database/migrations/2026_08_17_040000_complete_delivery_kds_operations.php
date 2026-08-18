<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_drivers')) {
            Schema::create('delivery_drivers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('name', 100);
                $table->string('phone', 24)->default('');
                $table->string('vehicle_type', 24)->default('motorcycle');
                $table->string('plate', 12)->default('');
                $table->string('status', 20)->default('available')->index();
                $table->boolean('active')->default(true)->index();
                $table->unsignedBigInteger('current_order_id')->nullable()->index();
                $table->timestamps();
                $table->unique(['tenant_id', 'phone']);
            });
        }

        if (! Schema::hasTable('kds_screens')) {
            Schema::create('kds_screens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('name', 80);
                $table->string('station', 40)->default('kitchen');
                $table->text('categories_json')->default('[]');
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'driver_id')) {
            Schema::table('orders', fn (Blueprint $table) => $table->unsignedBigInteger('driver_id')->nullable()->index());
        }
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'delivery_status')) {
            Schema::table('orders', fn (Blueprint $table) => $table->string('delivery_status', 24)->default('')->index());
        }
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'delivered_at')) {
            Schema::table('orders', fn (Blueprint $table) => $table->timestamp('delivered_at')->nullable());
        }

        if (Schema::hasTable('kds_screens') && ! \Illuminate\Support\Facades\DB::table('kds_screens')->where('tenant_id', 1)->exists()) {
            \Illuminate\Support\Facades\DB::table('kds_screens')->insert([
                'tenant_id' => 1,
                'name' => 'Cozinha',
                'station' => 'kitchen',
                'categories_json' => '[]',
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kds_screens');
        Schema::dropIfExists('delivery_drivers');
    }
};
