<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('restaurant_tables')) {
            Schema::create('restaurant_tables', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedInteger('number');
                $table->string('name');
                $table->string('status')->default('free');
                $table->string('customer')->default('');
                $table->string('session_id')->default('');
                $table->timestamp('updated_at')->nullable();
                $table->unique(['tenant_id', 'number']);
            });
        }

        if (! Schema::hasTable('order_drafts')) {
            Schema::create('order_drafts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('source')->default('counter');
                $table->string('customer')->default('');
                $table->text('payload');
                $table->timestamps();
            });
        }

        $this->addColumn('orders', 'notes', fn (Blueprint $table) => $table->text('notes')->default(''));
        $this->addColumn('orders', 'document', fn (Blueprint $table) => $table->string('document')->default(''));
        $this->addColumn('orders', 'discount_cents', fn (Blueprint $table) => $table->unsignedInteger('discount_cents')->default(0));
        $this->addColumn('orders', 'room_session_id', fn (Blueprint $table) => $table->string('room_session_id')->default(''));
        $this->addColumn('commands', 'updated_at', fn (Blueprint $table) => $table->timestamp('updated_at')->nullable());
        $this->addColumn('commands', 'session_id', fn (Blueprint $table) => $table->string('session_id')->default(''));

        if (DB::table('restaurant_tables')->where('tenant_id', 1)->doesntExist()) {
            $rows = [];
            for ($number = 1; $number <= 12; $number++) {
                $rows[] = ['tenant_id' => 1, 'number' => $number, 'name' => "Mesa {$number}", 'status' => 'free', 'customer' => '', 'session_id' => ''];
            }
            DB::table('restaurant_tables')->insert($rows);
        }
    }

    private function addColumn(string $tableName, string $column, callable $definition): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            return;
        }
        Schema::table($tableName, function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }

    public function down(): void
    {
        // Banco compartilhado com a versão anterior: rollback destrutivo é evitado.
    }
};
