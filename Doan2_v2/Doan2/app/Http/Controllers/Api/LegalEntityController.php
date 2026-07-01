<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Multi-entity management. All queries are tenant-scoped via the
 * LegalEntity model's BelongsToTenant global scope.
 */
class LegalEntityController extends Controller
{
    /**
     * Entity-scoped tables whose rows reference legal_entities.legal_entity_id.
     * Used by the destroy() guard to block deletion of in-use entities.
     */
    private const ENTITY_REFERENCING_TABLES = [
        'employees',
        'contracts',
        'departments',
        'salary_periods',
        'salary_details',
        'attendances',
        'attendance_logs',
    ];

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = LegalEntity::query()->orderByDesc('id');

        if (Schema::hasTable('employees')) {
            $query->withCount('employees');
        }
        if (Schema::hasTable('contracts')) {
            $query->withCount('contracts');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        $page = $query->paginate($perPage);

        return $this->ok([
            'items' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Legal entities list');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'tax_code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'meta' => 'nullable|array',
        ], [
            'name.required' => 'Tên công ty/pháp nhân là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $data['status'] = $data['status'] ?? 'ACTIVE';

        // tenant_id is auto-stamped by the BelongsToTenant trait on create.
        $entity = LegalEntity::create($data);

        return response()->json([
            'status' => 201,
            'message' => 'Công ty/pháp nhân đã được tạo',
            'data' => $entity->fresh(),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $entity = LegalEntity::query()
            ->withCount(['employees', 'contracts'])
            ->find($id);

        if (! $entity) {
            return $this->notFound();
        }

        return $this->ok($entity, 'Legal entity detail');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entity = LegalEntity::find($id);

        if (! $entity) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'nullable|string|max:50',
            'tax_code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'meta' => 'nullable|array',
        ], [
            'name.required' => 'Tên công ty/pháp nhân là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $allowed = ['name', 'code', 'tax_code', 'address', 'status', 'meta'];
        $data = collect($request->all())->only($allowed)->toArray();

        $entity->update($data);

        return $this->ok($entity->fresh(), 'Công ty/pháp nhân đã được cập nhật');
    }

    public function destroy(int $id): JsonResponse
    {
        $entity = LegalEntity::find($id);

        if (! $entity) {
            return $this->notFound();
        }

        $violations = [];

        // Block deletion when any entity-scoped row references this entity.
        foreach (self::ENTITY_REFERENCING_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
                continue;
            }

            $count = DB::table($table)->where('legal_entity_id', $id)->count();
            if ($count > 0) {
                $violations[] = "Không thể xóa: có {$count} bản ghi trong bảng '{$table}' thuộc công ty này";
            }
        }

        // Block deletion of the tenant's last/default legal entity.
        $remaining = LegalEntity::query()->count();
        if ($remaining <= 1) {
            $violations[] = 'Không thể xóa công ty mặc định/cuối cùng của tổ chức';
        } elseif ($id === (int) TenantContext::legalEntityId()) {
            $violations[] = 'Không thể xóa công ty đang là mặc định của tổ chức';
        }

        if (! empty($violations)) {
            return $this->conflict($violations, 'công ty/pháp nhân');
        }

        $entity->delete();

        return $this->ok(['id' => $id], 'Công ty/pháp nhân đã được xóa');
    }

    // ── Response Helpers ─────────────────────────────────

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(string $message = 'Record not found'): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $message, 'data' => null], 404);
    }

    private function conflict(array $violations, string $resourceName): JsonResponse
    {
        return response()->json([
            'status' => 409,
            'message' => "Không thể xóa {$resourceName} do vi phạm ràng buộc nghiệp vụ",
            'data' => ['violations' => $violations],
        ], 409);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ',
            'data' => ['errors' => $errors],
        ], 422);
    }
}
