<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nhắc hạn hợp đồng/thử việc/chứng chỉ — 07:00 hàng ngày (job tự chống spam).
Schedule::command('hrm:expiry-reminders')->dailyAt('07:00');

// Hết hạn phép gộp sau deadline nội quy (mặc định 31/03) — tự bỏ qua khi chưa tới hạn.
Schedule::command('hrm:leave-carryover-expire')->dailyAt('06:45');

Schedule::command('attendance:create-next-partition')
    ->monthlyOn(1, '00:05')
    ->withoutOverlapping();

Schedule::call(function (): void {
    if (Schema::hasTable('attendance_change_events')) {
        \Illuminate\Support\Facades\DB::table('attendance_change_events')
            ->where('created_at', '<', now()->subDays(7))
            ->delete();
    }
    $expired = Schema::hasTable('attendance_timesheet_exports')
        ? \App\Models\AttendanceTimesheetExport::withoutTenantScope()
            ->where('expires_at', '<=', now())
            ->get()
        : collect();
    foreach ($expired as $export) {
        if ($export->file_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($export->file_path);
        }
        $export->delete();
    }
})->hourly()->name('attendance-cleanup')->withoutOverlapping();
