<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Công khoán theo sản phẩm (piece-rate). Ghi nhận sản lượng → amount = SL × đơn
 * giá. PayrollRunService cộng tổng amount của kỳ vào thu nhập của nhân viên.
 */
class PieceRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('piece_rate_entries')
            ->where('tenant_id', TenantContext::id())
            ->when($request->filled('employee_id'), fn ($x) => $x->where('employee_id', $request->query('employee_id')))
            ->when($request->filled('from'), fn ($x) => $x->whereDate('work_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($x) => $x->whereDate('work_date', '<=', $request->query('to')))
            ->orderByDesc('work_date')->orderByDesc('id')
            ->limit(500)
            ->get();

        return $this->ok($q, 'Danh sách công khoán');
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'work_date' => 'required|date',
            'product_name' => 'required|string|max:255',
            'quantity' => 'required|numeric|min:0',
            'unit_rate' => 'required|numeric|min:0',
        ], [
            'employee_id.required' => 'Mã nhân viên là bắt buộc',
            'product_name.required' => 'Tên sản phẩm là bắt buộc',
        ]);
        if ($v->fails()) {
            return $this->validationError($v->errors()->toArray());
        }
        if (! TenantContext::ownsRow('employees', $request->input('employee_id'))) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        $qty = (float) $request->input('quantity');
        $rate = (float) $request->input('unit_rate');
        $now = now();
        $id = DB::table('piece_rate_entries')->insertGetId([
            'tenant_id' => TenantContext::id(),
            'legal_entity_id' => TenantContext::legalEntityId(),
            'employee_id' => (int) $request->input('employee_id'),
            'work_date' => $request->input('work_date'),
            'product_name' => $request->input('product_name'),
            'quantity' => $qty,
            'unit_rate' => $rate,
            'amount' => round($qty * $rate, 2),
            'meta' => $request->filled('note') ? json_encode(['note' => $request->input('note')], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'status' => 201,
            'message' => 'Đã ghi nhận công khoán',
            'data' => DB::table('piece_rate_entries')->find($id),
        ], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $row = DB::table('piece_rate_entries')->where('id', $id)->where('tenant_id', TenantContext::id())->first();
        if (! $row) {
            return response()->json(['status' => 404, 'message' => 'Không tìm thấy', 'data' => null], 404);
        }
        DB::table('piece_rate_entries')->where('id', $id)->delete();

        return $this->ok(['id' => $id], 'Đã xoá');
    }

    private function ok($data, string $msg): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $msg, 'data' => $data]);
    }

    private function validationError(array $e): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $e]], 422);
    }
}
