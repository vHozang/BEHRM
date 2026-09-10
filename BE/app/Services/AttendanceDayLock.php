<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;

class AttendanceDayLock
{
    public function run(int $tenantId, int $employeeId, string $workDate, Closure $callback): mixed
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $workDate, $callback): mixed {
            if (DB::getDriverName() === 'pgsql') {
                $key = (int) sprintf('%u', crc32("attendance:{$tenantId}:{$employeeId}:{$workDate}"));
                DB::select('SELECT pg_advisory_xact_lock(?)', [$key]);
            }

            return $callback();
        });
    }
}
