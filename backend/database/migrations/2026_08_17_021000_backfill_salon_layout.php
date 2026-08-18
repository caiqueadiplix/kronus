<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tenants = DB::table('tenants')->pluck('id');
        foreach ($tenants as $tenantId) {
            $sector = DB::table('salon_sectors')->where('tenant_id', $tenantId)->orderBy('id')->first();
            if (! $sector) {
                $sectorId = DB::table('salon_sectors')->insertGetId(['tenant_id' => $tenantId, 'name' => 'Salão principal', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $sectorId = $sector->id;
            }
            $tables = DB::table('restaurant_tables')->where('tenant_id', $tenantId)->orderBy('number')->get();
            foreach ($tables as $index => $table) {
                $column = $index % 4;
                $row = intdiv($index, 4);
                DB::table('restaurant_tables')->where('id', $table->id)->update([
                    'sector_id' => $table->sector_id ?: $sectorId,
                    'position_x' => 12 + ($column * 24),
                    'position_y' => 15 + ($row * 24),
                    'seats' => $table->seats ?: 4,
                    'shape' => $table->shape ?: 'square',
                ]);
            }
        }
    }

    public function down(): void
    {
        // Backfill não é revertido para preservar posições operacionais.
    }
};
