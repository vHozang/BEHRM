<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_balances')
            ->whereNull('remaining_days')
            ->orderBy('id')
            ->chunkById(500, function ($balances): void {
                foreach ($balances as $balance) {
                    $meta = is_string($balance->meta) ? json_decode($balance->meta, true) : (array) $balance->meta;
                    $carryover = is_array($meta) ? (float) ($meta['carried_over_days'] ?? 0) : 0.0;

                    DB::table('leave_balances')->where('id', $balance->id)->update([
                        'remaining_days' => (float) $balance->total_days + $carryover - (float) $balance->used_days,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // ponytail: repaired business data must not be turned back into nulls.
    }
};
