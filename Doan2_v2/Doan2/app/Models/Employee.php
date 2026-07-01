<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use Auditable, BelongsToTenant;

    const TENANT_ENTITY_SCOPED = true;

    protected $guarded = ['id'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'date_of_birth' => 'date:Y-m-d',
            'hire_date' => 'date:Y-m-d',
        ];
    }

    public function setStatusAttribute($value)
    {
        $this->attributes['status'] = $value ? strtoupper($value) : null;
    }

    // ── Relationships ────────────────────────────────────

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)
            ->whereIn('status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC']);
    }

    public function employeeRoles(): HasMany
    {
        return $this->hasMany(EmployeeRole::class);
    }

    public function activeRoles(): HasMany
    {
        return $this->hasMany(EmployeeRole::class)->whereRaw('is_active = true');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    // ── Scopes ───────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    // ── Business Logic ───────────────────────────────────

    public function hasActiveContract(): bool
    {
        return $this->contracts()
            ->whereIn('status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
            ->exists();
    }

    public function hasUnpaidSalary(): bool
    {
        return \DB::table('salary_details')
            ->where('employee_id', $this->id)
            ->whereNotIn('transfer_status', ['COMPLETED', 'ĐÃ_CHUYỂN', 'DA_CHUYEN'])
            ->exists();
    }

    public function hasUnreturnedAssets(): bool
    {
        return \DB::table('asset_assignments')
            ->where('employee_id', $this->id)
            ->whereNotIn('status', ['RETURNED', 'ĐÃ_TRẢ', 'DA_TRA'])
            ->exists();
    }

    public function hasPendingRequests(): bool
    {
        return \DB::table('requests')
            ->where('requester_id', $this->id)
            ->whereIn('status', ['PENDING', 'IN_PROGRESS', 'ĐANG_XỬ_LÝ', 'CHỜ_DUYỆT'])
            ->exists();
    }

    public function hasSubordinates(): bool
    {
        return static::where('manager_id', $this->id)
            ->where('status', '!=', 'TERMINATED')
            ->exists();
    }

    public function deletionViolations(): array
    {
        $violations = [];

        if ($this->hasActiveContract()) {
            $violations[] = 'Không thể xóa nhân viên đang có hợp đồng hiệu lực';
        }
        if ($this->hasUnpaidSalary()) {
            $violations[] = 'Không thể xóa nhân viên có lương chưa thanh toán';
        }
        if ($this->activeRoles()->exists()) {
            $violations[] = 'Không thể xóa nhân viên đang giữ vai trò quản lý/phân quyền';
        }
        if ($this->hasUnreturnedAssets()) {
            $violations[] = 'Không thể xóa nhân viên đang giữ tài sản chưa trả';
        }
        if ($this->hasPendingRequests()) {
            $violations[] = 'Không thể xóa nhân viên có yêu cầu đang chờ xử lý';
        }
        if ($this->hasSubordinates()) {
            $violations[] = 'Không thể xóa nhân viên đang quản lý nhân viên khác — hãy chuyển người quản lý trước';
        }

        return $violations;
    }
}
