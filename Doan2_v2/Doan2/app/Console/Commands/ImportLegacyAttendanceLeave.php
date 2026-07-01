<?php

namespace App\Console\Commands;

class ImportLegacyAttendanceLeave extends BaseLegacyImportCommand
{
    protected $signature = 'legacy:import-attendance-leave';

    protected $description = 'Import legacy attendance, overtime, and leave records.';

    protected array $tables = [
        'leave_types', 'holidays', 'leave_balances', 'seniority_leave_history',
        'leave_advancement_config', 'leave_carryover_tracking', 'leave_requests',
        'leave_advancement_requests', 'leave_transactions', 'shift_types',
        'shift_schedules', 'shift_schedule_details', 'shift_assignments',
        'shift_swaps', 'attendances', 'overtime_requests',
    ];
}
