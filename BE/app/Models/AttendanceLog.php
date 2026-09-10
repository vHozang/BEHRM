<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model đại diện cho bảng `attendance_logs` (partitioned table).
 *
 * Lưu ý quan trọng khi làm việc với Partitioned Table trong Eloquent:
 *  - Không dùng ->find($id) vì PG cần cả partition key (checked_at) để locate row.
 *    Dùng ->where('id', $id)->where('checked_at', $date)->first() thay thế.
 *  - Upsert và mass-update hoạt động bình thường.
 */
class AttendanceLog extends Model
{
    use Auditable, BelongsToTenant;

    const TENANT_ENTITY_SCOPED = true;

    protected $table = 'attendance_logs';

    /**
     * Không có updated_at trong partitioned table để tránh fragmentation.
     * Append-only design: không UPDATE, chỉ INSERT record mới nếu cần đính chính.
     */
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * Các cột được cast tự động.
     * jsonb_path_ops GIN index cho phép query: AttendanceLog::whereJsonContains('metadata->gps', ...).
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',  // JSONB → PHP array
            'checked_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ── Scopes ────────────────────────────────────────────

    /** Chỉ lấy record chưa được xử lý bởi worker */
    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    /** Lọc theo khoảng thời gian, giúp PG dùng partition pruning (chỉ scan partition liên quan) */
    public function scopeInMonth($query, int $year, int $month)
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = now()->create($year, $month, 1)->endOfMonth()->toDateTimeString();

        return $query->whereBetween('checked_at', [$start, $end]);
    }
}
