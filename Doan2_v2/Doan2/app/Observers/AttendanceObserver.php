<?php

namespace App\Observers;

use App\Models\Attendance;
use App\Services\AttendanceChangePublisher;

class AttendanceObserver
{
    public function __construct(private readonly AttendanceChangePublisher $publisher) {}

    public function created(Attendance $attendance): void
    {
        $this->publisher->publish($attendance, 'created');
    }

    public function updated(Attendance $attendance): void
    {
        $this->publisher->publish($attendance, 'updated');
    }

    public function deleted(Attendance $attendance): void
    {
        $this->publisher->publish($attendance, 'deleted');
    }
}
