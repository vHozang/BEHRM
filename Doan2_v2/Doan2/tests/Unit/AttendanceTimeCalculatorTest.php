<?php

namespace Tests\Unit;

use App\Services\AttendanceTimeCalculator;
use Tests\TestCase;

class AttendanceTimeCalculatorTest extends TestCase
{
    private AttendanceTimeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AttendanceTimeCalculator();
    }

    public function test_early_arrival_and_late_departure_do_not_increase_regular_minutes(): void
    {
        $result = $this->calculator->calculate(
            ['start_time' => '06:00:00', 'end_time' => '14:00:00'],
            '2026-08-11',
            '05:45:00',
            '14:15:00',
        );

        $this->assertSame(480, $result['regular_worked_minutes']);
        $this->assertSame(15, $result['early_arrival_minutes']);
        $this->assertSame(15, $result['after_shift_minutes']);
        $this->assertSame('ON_TIME', $result['status']);
    }

    public function test_exactly_fifteen_minutes_late_or_early_is_a_violation(): void
    {
        $late = $this->calculator->calculate(
            ['start_time' => '06:00:00', 'end_time' => '14:00:00'],
            '2026-08-11',
            '06:15:00',
            '14:00:00',
        );
        $early = $this->calculator->calculate(
            ['start_time' => '06:00:00', 'end_time' => '14:00:00'],
            '2026-08-11',
            '06:00:00',
            '13:45:00',
        );

        $this->assertSame('LATE', $late['status']);
        $this->assertSame(15, $late['late_minutes']);
        $this->assertSame('EARLY_LEAVE', $early['status']);
        $this->assertSame(15, $early['early_leave_minutes']);
    }

    public function test_administrative_shift_excludes_the_lunch_break(): void
    {
        $result = $this->calculator->calculate(
            [
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'break_start' => '12:00:00',
                'break_end' => '13:00:00',
            ],
            '2026-08-11',
            '07:55:00',
            '17:10:00',
        );

        $this->assertSame(480, $result['scheduled_minutes']);
        $this->assertSame(480, $result['regular_worked_minutes']);
    }

    public function test_split_shift_and_two_sessions_use_the_same_intersection_engine(): void
    {
        $result = $this->calculator->calculate(
            [
                'start_time' => '08:00:00',
                'end_time' => '18:00:00',
                'meta' => ['segments' => [
                    ['start' => '08:00', 'end' => '12:00'],
                    ['start' => '14:00', 'end' => '18:00'],
                ]],
            ],
            '2026-08-11',
            '07:50:00',
            '12:10:00',
            '13:50:00',
            '18:15:00',
        );

        $this->assertSame(480, $result['regular_worked_minutes']);
        $this->assertSame(10, $result['early_arrival_minutes']);
        $this->assertSame(15, $result['after_shift_minutes']);
    }

    public function test_overnight_shift_is_anchored_to_the_shift_start_date(): void
    {
        $result = $this->calculator->calculate(
            ['start_time' => '22:00:00', 'end_time' => '06:00:00'],
            '2026-08-11',
            '21:45:00',
            '06:15:00',
        );

        $this->assertSame(480, $result['regular_worked_minutes']);
        $this->assertSame('2026-08-12 06:00:00', $result['shift_end']);
        $this->assertSame('2026-08-12 06:15:00', $result['presence_intervals'][0]['end']);
        $this->assertSame(15, $result['early_arrival_minutes']);
        $this->assertSame(15, $result['after_shift_minutes']);
    }
}
