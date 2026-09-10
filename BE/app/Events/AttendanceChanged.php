<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceChanged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public bool $afterCommit = true;

    public string $queue = 'realtime';

    /** @param array<string, mixed> $change */
    public function __construct(public array $change) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $tenantId = (int) $this->change['tenant_id'];
        $channels = [
            new PrivateChannel("attendance.tenant.{$tenantId}.all"),
        ];

        if (! empty($this->change['employee_id'])) {
            $channels[] = new PrivateChannel('attendance.employee.'.(int) $this->change['employee_id']);
        }

        if (! empty($this->change['legal_entity_id'])) {
            $channels[] = new PrivateChannel("attendance.tenant.{$tenantId}.entity.{$this->change['legal_entity_id']}");
        }
        if (! empty($this->change['department_id'])) {
            $channels[] = new PrivateChannel("attendance.tenant.{$tenantId}.department.{$this->change['department_id']}");
        }
        foreach ((array) ($this->change['department_ids'] ?? []) as $departmentId) {
            $channels[] = new PrivateChannel("attendance.tenant.{$tenantId}.department.".(int) $departmentId);
        }
        foreach ((array) ($this->change['employee_ids'] ?? []) as $employeeId) {
            $channels[] = new PrivateChannel('attendance.employee.'.(int) $employeeId);
        }

        return collect($channels)->unique(fn (PrivateChannel $channel) => $channel->name)->values()->all();
    }

    public function broadcastAs(): string
    {
        return 'attendance.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return collect($this->change)->only([
            'cursor', 'attendance_id', 'employee_id', 'legal_entity_id', 'department_id',
            'work_date', 'change_type', 'version', 'updated_at',
        ])->all();
    }

    public function broadcastWhen(): bool
    {
        return config('broadcasting.default') !== 'null';
    }
}
