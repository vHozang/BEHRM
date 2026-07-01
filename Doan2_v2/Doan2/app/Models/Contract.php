<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use Auditable, BelongsToTenant;

    const TENANT_ENTITY_SCOPED = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'meta' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class, 'contract_type_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(ContractChangeLog::class);
    }

    public function salaryDetails(): HasMany
    {
        return $this->hasMany(SalaryDetail::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'], true);
    }

    public function deletionViolations(): array
    {
        $violations = [];

        if ($this->isActive()) {
            $violations[] = 'Không thể xóa hợp đồng đang còn hiệu lực';
        }
        if ($this->salaryDetails()->exists()) {
            $violations[] = 'Không thể xóa hợp đồng đã có dữ liệu lương';
        }

        return $violations;
    }
}
