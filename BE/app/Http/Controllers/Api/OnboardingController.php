<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingTask;
use App\Services\OnboardingService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Light onboarding / offboarding checklists (danh sách công việc khi nhân viên
 * vào / nghỉ việc). All reads/writes are tenant-scoped via the models' global
 * scope; cross-tenant employee references are rejected with ownsRow().
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $service)
    {
    }

    /** Whether the requester manages onboarding (full access or hr module) vs a
     *  regular employee who may only see/tick their own checklist. */
    private function isManager(Request $request): bool
    {
        $access = $request->attributes->get('access');
        if (! is_array($access)) {
            return true;
        }

        return ! empty($access['full']) || in_array('hr', $access['modules'] ?? [], true);
    }

    private function authEmpId(Request $request): ?int
    {
        $id = $request->attributes->get('auth_employee_id');

        return $id !== null ? (int) $id : null;
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $isManager = $this->isManager($request);

        $query = OnboardingChecklist::query()
            ->with('employee:id,full_name')
            ->withCount(['tasks', 'tasks as done_tasks_count' => fn ($q) => $q->whereRaw('is_done = true')])
            ->orderByDesc('id');

        foreach (['type', 'status', 'employee_id'] as $field) {
            if ($field === 'employee_id' && ! $isManager) {
                continue; // employees are forced to their own below
            }
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        // Non-managers only ever see their own checklists.
        if (! $isManager) {
            $query->where('employee_id', $this->authEmpId($request));
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
        ], 'Onboarding checklists list');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $checklist = OnboardingChecklist::with(['employee:id,full_name', 'tasks' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->find($id);

        if (! $checklist) {
            return $this->notFound();
        }

        // Employees may only view their own checklist.
        if (! $this->isManager($request) && (int) $checklist->employee_id !== $this->authEmpId($request)) {
            return $this->notFound();
        }

        return $this->ok($checklist, 'Onboarding checklist detail');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
            'type' => 'nullable|string',
            'start_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ], [
            'employee_id.required' => 'Mã nhân viên là bắt buộc',
            'employee_id.exists' => 'Nhân viên không tồn tại',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = (int) $request->input('employee_id');

        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        $checklist = $this->service->createChecklist(
            $employeeId,
            (string) $request->input('type', 'ONBOARDING'),
            $request->input('start_date'),
            $request->input('notes')
        );

        $checklist->load(['employee:id,full_name', 'tasks' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);

        return response()->json([
            'status' => 201,
            'message' => 'Đã tạo danh sách công việc',
            'data' => $checklist,
        ], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $checklist = OnboardingChecklist::find($id);

        if (! $checklist) {
            return $this->notFound();
        }

        $status = strtoupper((string) $request->input('status'));
        if (! in_array($status, ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true)) {
            return $this->validationError(['status' => ['Trạng thái không hợp lệ']]);
        }

        $checklist->update(['status' => $status]);

        return $this->ok($checklist->fresh(), 'Đã cập nhật trạng thái');
    }

    public function destroy(int $id): JsonResponse
    {
        $checklist = OnboardingChecklist::find($id);

        if (! $checklist) {
            return $this->notFound();
        }

        // Tasks are removed by the FK cascade.
        $checklist->delete();

        return $this->ok(['id' => $id], 'Đã xóa danh sách công việc');
    }

    public function addTask(Request $request, int $id): JsonResponse
    {
        $checklist = OnboardingChecklist::find($id);

        if (! $checklist) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
        ], [
            'title.required' => 'Tên công việc là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $nextOrder = (int) ($checklist->tasks()->max('sort_order') ?? -1) + 1;

        // is_done omitted — defaults to false (avoids the PG boolean bind issue).
        $task = OnboardingTask::create([
            'checklist_id' => $checklist->id,
            'title' => trim((string) $request->input('title')),
            'description' => $request->input('description'),
            'due_date' => $request->input('due_date'),
            'sort_order' => $nextOrder,
        ]);

        $this->service->recomputeStatus($checklist);

        return response()->json([
            'status' => 201,
            'message' => 'Đã thêm công việc',
            'data' => $task->fresh(),
        ], 201);
    }

    public function updateTask(Request $request, int $id, int $taskId): JsonResponse
    {
        $task = $this->resolveTask($id, $taskId);

        if (! $task) {
            return $this->notFound();
        }

        $isManager = $this->isManager($request);

        // Employees may only tick (is_done) tasks on THEIR OWN checklist.
        if (! $isManager) {
            $checklist = OnboardingChecklist::find($id);
            if (! $checklist || (int) $checklist->employee_id !== $this->authEmpId($request)) {
                return $this->notFound();
            }
        }

        // Build a raw update: is_done must be written as a PG boolean literal
        // (an Eloquent bool cast binds an integer that PG rejects). The task was
        // already resolved through the tenant-scoped model, so updating by id is safe.
        $updates = ['updated_at' => now()];
        if ($request->has('is_done')) {
            $done = filter_var($request->input('is_done'), FILTER_VALIDATE_BOOLEAN);
            $updates['is_done'] = DB::raw($done ? 'true' : 'false');
            $updates['done_at'] = $done ? now() : null;
        }
        // Editing task content (title/description/due_date) is manager-only.
        if ($isManager) {
            foreach (['title', 'description', 'due_date'] as $field) {
                if ($request->has($field)) {
                    $updates[$field] = $request->input($field);
                }
            }
        }

        DB::table('onboarding_tasks')->where('id', $task->id)->update($updates);
        $task = $task->fresh();

        $checklist = OnboardingChecklist::find($id);
        $status = $checklist ? $this->service->recomputeStatus($checklist) : null;

        return $this->ok(['task' => $task->fresh(), 'checklist_status' => $status], 'Đã cập nhật công việc');
    }

    public function deleteTask(int $id, int $taskId): JsonResponse
    {
        $task = $this->resolveTask($id, $taskId);

        if (! $task) {
            return $this->notFound();
        }

        $task->delete();

        $checklist = OnboardingChecklist::find($id);
        if ($checklist) {
            $this->service->recomputeStatus($checklist);
        }

        return $this->ok(['id' => $taskId], 'Đã xóa công việc');
    }

    private function resolveTask(int $checklistId, int $taskId): ?OnboardingTask
    {
        return OnboardingTask::where('id', $taskId)
            ->where('checklist_id', $checklistId)
            ->first();
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

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ',
            'data' => ['errors' => $errors],
        ], 422);
    }
}
