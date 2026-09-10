<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Department membership (matrix-aware).
 *
 * Department CRUD itself goes through GenericResourceController (/departments).
 * This adds member management: an employee's PRIMARY department is
 * employees.department_id; ADDITIONAL (matrix) memberships live in the
 * employee_departments pivot. So a department's members = primary members
 * (department_id = this dept) + secondary members (pivot rows).
 */
class DepartmentController extends Controller
{
    /** GET /departments/{id}/members */
    public function members(int $id): JsonResponse
    {
        $tenantId = TenantContext::id();

        $primary = DB::table('employees')
            ->where('department_id', $id)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get(['id', 'full_name', 'employee_code', 'position_id'])
            ->map(fn ($e) => (array) $e + ['membership' => 'primary', 'pivot_id' => null])
            ->all();

        $secondary = DB::table('employee_departments as ed')
            ->join('employees as e', 'e.id', '=', 'ed.employee_id')
            ->where('ed.department_id', $id)
            ->when($tenantId !== null, fn ($q) => $q->where('ed.tenant_id', $tenantId))
            ->get(['e.id', 'e.full_name', 'e.employee_code', 'e.position_id', 'ed.id as pivot_id', 'ed.role_in_dept'])
            ->map(fn ($e) => (array) $e + ['membership' => 'secondary'])
            ->all();

        return $this->ok([
            'primary' => $primary,
            'secondary' => $secondary,
        ], 'Department members');
    }

    /**
     * GET /departments/cost-summary — sĩ số + quỹ lương tháng (gross) theo từng
     * phòng ban (tính từ HĐ đang hiệu lực của nhân viên chính thức). Dùng để
     * hiển thị chi phí nhân sự + so với ngân sách phòng.
     */
    public function costSummary(): JsonResponse
    {
        $tenantId = TenantContext::id();

        $rows = DB::table('employees as e')
            ->join('contracts as c', function ($j) {
                $j->on('c.employee_id', '=', 'e.id')->where('c.status', '=', 'CÓ_HIỆU_LỰC');
            })
            ->whereNotNull('e.department_id')
            ->where('e.status', '!=', 'TERMINATED')
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
            ->groupBy('e.department_id')
            ->select(
                'e.department_id',
                DB::raw('COUNT(*) as headcount'),
                DB::raw("SUM(COALESCE(NULLIF(c.meta->>'basic_salary','')::numeric,0) + COALESCE(NULLIF(c.meta->>'allowances','')::numeric,0)) as total_salary")
            )
            ->get();

        return $this->ok($rows, 'Department cost summary');
    }

    /**
     * POST /departments/{id}/remove-employee  body: { employee_id }
     * Gỡ nhân viên khỏi phòng ban CHÍNH → department_id = null (chưa phân phòng).
     * Đóng dòng lịch sử công tác hiện tại. (Không phải kiêm nhiệm.)
     */
    public function unassignEmployee(Request $request, int $id): JsonResponse
    {
        $employeeId = (int) $request->input('employee_id');
        if (! $employeeId) {
            return $this->validationError(['employee_id' => ['Mã nhân viên là bắt buộc']]);
        }
        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        $emp = DB::table('employees')->where('id', $employeeId)->first(['id', 'department_id']);
        if (! $emp || (int) $emp->department_id !== $id) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc phòng ban này']]);
        }

        DB::transaction(function () use ($employeeId) {
            DB::table('employees')->where('id', $employeeId)->update(['department_id' => null, 'updated_at' => now()]);
            if (Schema::hasTable('employment_histories')) {
                DB::table('employment_histories')->where('employee_id', $employeeId)
                    ->whereRaw('is_current = true')
                    ->update(['is_current' => DB::raw('false'), 'end_date' => now()->toDateString(), 'updated_at' => now()]);
            }
        });

        return $this->ok(['employee_id' => $employeeId], 'Đã gỡ nhân viên khỏi phòng ban (chưa phân phòng)');
    }

    /** POST /departments/{id}/members  body: { employee_id, role_in_dept? } */
    public function addMember(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
            'role_in_dept' => 'nullable|string|max:255',
        ], ['employee_id.required' => 'Mã nhân viên là bắt buộc']);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = (int) $request->input('employee_id');

        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }
        if (! TenantContext::ownsRow('departments', $id)) {
            return $this->notFound();
        }

        // Already the primary department → no secondary membership needed.
        $primaryDept = DB::table('employees')->where('id', $employeeId)->value('department_id');
        if ((int) $primaryDept === $id) {
            return $this->validationError(['employee_id' => ['Nhân viên đã thuộc phòng ban này (phòng ban chính)']]);
        }

        $exists = DB::table('employee_departments')
            ->where('employee_id', $employeeId)->where('department_id', $id)->exists();
        if ($exists) {
            return $this->validationError(['employee_id' => ['Nhân viên đã được gán vào phòng ban này']]);
        }

        DB::table('employee_departments')->insert(TenantContext::stamp([
            'employee_id' => $employeeId,
            'department_id' => $id,
            'role_in_dept' => $request->input('role_in_dept'),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(['status' => 201, 'message' => 'Đã gán nhân viên (kiêm nhiệm) vào phòng ban', 'data' => ['employee_id' => $employeeId, 'department_id' => $id]], 201);
    }

    /**
     * POST /departments/{id}/assign-employee  body: { employee_id }
     * Đặt phòng ban CHÍNH (employees.department_id) của nhân viên = phòng này —
     * dùng để THÊM nhân viên vào phòng / ĐIỀU CHUYỂN từ phòng khác sang. Tự ghi
     * nhận lịch sử công tác khi đổi phòng. (Khác với kiêm nhiệm ở addMember.)
     */
    public function assignEmployee(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
        ], ['employee_id.required' => 'Mã nhân viên là bắt buộc']);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = (int) $request->input('employee_id');

        if (! TenantContext::ownsRow('departments', $id)) {
            return $this->notFound();
        }
        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        $emp = DB::table('employees')->where('id', $employeeId)->first(['id', 'department_id', 'position_id']);
        if ((int) $emp->department_id === $id) {
            return $this->validationError(['employee_id' => ['Nhân viên đã thuộc phòng ban này']]);
        }

        DB::transaction(function () use ($employeeId, $id, $emp) {
            DB::table('employees')->where('id', $employeeId)->update([
                'department_id' => $id,
                'updated_at' => now(),
            ]);

            // Nếu trước đó là kiêm nhiệm phòng này thì gỡ pivot (đã thành phòng chính).
            DB::table('employee_departments')->where('employee_id', $employeeId)->where('department_id', $id)->delete();

            // Ghi lịch sử công tác: đóng dòng hiện tại + mở dòng mới.
            if (Schema::hasTable('employment_histories')) {
                $today = now()->toDateString();
                DB::table('employment_histories')->where('employee_id', $employeeId)
                    ->whereRaw('is_current = true')
                    ->update(['is_current' => DB::raw('false'), 'end_date' => $today, 'updated_at' => now()]);
                DB::table('employment_histories')->insert(TenantContext::stamp([
                    'employee_id' => $employeeId,
                    'department_id' => $id,
                    'position_id' => $emp->position_id,
                    'start_date' => $today,
                    'is_current' => DB::raw('true'),
                    'decision_number' => 'QĐ-ĐC/' . $employeeId,
                    'decision_date' => $today,
                    'notes' => 'Điều chuyển / phân bổ vào phòng ban',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        });

        return $this->ok(['employee_id' => $employeeId, 'department_id' => $id], 'Đã thêm/điều chuyển nhân viên vào phòng ban');
    }

    /** DELETE /departments/{id}/members/{employeeId} — removes a secondary membership only. */
    public function removeMember(int $id, int $employeeId): JsonResponse
    {
        $deleted = DB::table('employee_departments')
            ->where('department_id', $id)
            ->where('employee_id', $employeeId)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
            ->delete();

        if (! $deleted) {
            return $this->validationError(['employee_id' => ['Không thể gỡ: đây là phòng ban chính của nhân viên (đổi phòng ban chính trong hồ sơ nhân viên)']]);
        }

        return $this->ok(['employee_id' => $employeeId, 'department_id' => $id], 'Đã gỡ nhân viên khỏi phòng ban');
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(string $message = 'Record not found'): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $message, 'data' => null], 404);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $errors]], 422);
    }
}
