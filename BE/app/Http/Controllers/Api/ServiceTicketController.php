<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AccessControl;
use App\Support\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServiceTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $manager = $this->canManage($request);
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $base = DB::table('service_tickets as t')
            ->leftJoin('service_categories as c', function ($join): void {
                $join->on('c.id', '=', 't.category_id')->on('c.tenant_id', '=', 't.tenant_id');
            })
            ->leftJoin('employees as e', function ($join): void {
                $join->on('e.id', '=', 't.requester_id')->on('e.tenant_id', '=', 't.tenant_id');
            })
            ->where('t.tenant_id', TenantContext::id())
            ->when(! $manager, fn ($query) => $query->where('t.requester_id', $this->actor($request)));

        foreach (['status', 'priority', 'category_id'] as $field) {
            if ($request->filled($field)) {
                $base->where('t.'.$field, $request->query($field));
            }
        }
        if ($request->filled('search')) {
            $search = '%'.strtolower(trim((string) $request->query('search'))).'%';
            $base->where(fn ($query) => $query->whereRaw('lower(t.ticket_code) like ?', [$search])
                ->orWhereRaw('lower(t.title) like ?', [$search]));
        }

        $summary = (clone $base)->selectRaw('count(*) as total')
            ->selectRaw("sum(case when upper(t.status) in ('PENDING','OPEN') then 1 else 0 end) as pending")
            ->selectRaw("sum(case when upper(t.status) = 'IN_PROGRESS' then 1 else 0 end) as in_progress")
            ->selectRaw("sum(case when upper(t.status) in ('RESOLVED','CLOSED') then 1 else 0 end) as completed")
            ->first();
        $page = $base->orderByDesc('t.id')->select([
            't.*', 'c.category_code', 'c.category_name', 'e.employee_code', 'e.full_name as requester_name',
        ])->paginate($perPage);

        return $this->ok([
            'items' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'summary' => $summary,
        ], 'Danh sách ticket');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT,low,normal,high,urgent'],
        ]);
        $this->assertCategory((int) $data['category_id']);
        $id = DB::transaction(function () use ($data, $request): int {
            $id = DB::table('service_tickets')->insertGetId(TenantContext::stamp([
                'ticket_code' => 'TK-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'requester_id' => $this->actor($request),
                'category_id' => $data['category_id'],
                'title' => trim($data['title']),
                'description' => trim($data['description']),
                'priority' => strtoupper((string) ($data['priority'] ?? 'NORMAL')),
                'status' => 'PENDING',
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->appendUpdate($id, $request, 'CREATED', null, 'PENDING', 'Ticket được tạo');

            return $id;
        });

        $ticket = DB::table('service_tickets')->where('tenant_id', TenantContext::id())->where('id', $id)->first();

        return response()->json(['status' => 201, 'message' => 'Đã tạo ticket', 'data' => $ticket], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = $this->ticketFor($request, $id);
        if (! $ticket) {
            return $this->notFound();
        }
        $ticket->updates = DB::table('service_ticket_updates as u')
            ->leftJoin('employees as e', function ($join): void {
                $join->on('e.id', '=', 'u.created_by')->on('e.tenant_id', '=', 'u.tenant_id');
            })
            ->where('u.tenant_id', TenantContext::id())->where('u.ticket_id', $id)
            ->orderBy('u.id')->get(['u.*', 'e.full_name as created_by_name']);

        return $this->ok($ticket, 'Chi tiết ticket');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless($this->canManage($request), 403, 'Bạn không có quyền xử lý ticket');
        $ticket = $this->ticketFor($request, $id, true);
        if (! $ticket) {
            return $this->notFound();
        }
        $data = $request->validate([
            'status' => ['sometimes', 'in:PENDING,IN_PROGRESS,RESOLVED,CLOSED,CANCELLED'],
            'priority' => ['sometimes', 'in:LOW,NORMAL,HIGH,URGENT'],
            'assigned_to' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);
        $nextStatus = $data['status'] ?? $ticket->status;
        $this->assertTransition((string) $ticket->status, (string) $nextStatus);
        DB::transaction(function () use ($data, $ticket, $id, $request, $nextStatus): void {
            $payload = collect($data)->only(['status', 'priority', 'assigned_to'])->toArray();
            if ($payload !== []) {
                DB::table('service_tickets')->where('tenant_id', TenantContext::id())->where('id', $id)
                    ->update([...$payload, 'updated_at' => now()]);
            }
            if (($data['comment'] ?? null) || $nextStatus !== $ticket->status) {
                $this->appendUpdate($id, $request, 'UPDATED', (string) $ticket->status, (string) $nextStatus, $data['comment'] ?? null);
            }
        });
        AuditLogger::log('update', 'service_tickets', $id, (array) $ticket, (array) DB::table('service_tickets')->find($id));

        return $this->show($request, $id);
    }

    public function addUpdate(Request $request, int $id): JsonResponse
    {
        $ticket = $this->ticketFor($request, $id);
        if (! $ticket) {
            return $this->notFound();
        }
        $data = $request->validate(['comment' => ['required', 'string', 'max:5000']]);
        $this->appendUpdate($id, $request, 'COMMENT', (string) $ticket->status, (string) $ticket->status, $data['comment']);

        return $this->show($request, $id);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $ticket = $this->ticketFor($request, $id);
        if (! $ticket) {
            return $this->notFound();
        }
        $isOwner = (int) $ticket->requester_id === $this->actor($request);
        abort_unless($isOwner || $this->canManage($request), 403);
        if ($isOwner && ! in_array(strtoupper((string) $ticket->status), ['PENDING', 'OPEN'], true)) {
            throw ValidationException::withMessages(['status' => ['Chỉ được hủy ticket chưa bắt đầu xử lý']]);
        }
        DB::transaction(function () use ($id, $ticket, $request): void {
            DB::table('service_tickets')->where('tenant_id', TenantContext::id())->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            $this->appendUpdate($id, $request, 'CANCELLED', (string) $ticket->status, 'CANCELLED', $request->input('comment'));
        });

        return $this->ok(['id' => $id, 'status' => 'CANCELLED'], 'Đã hủy ticket');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->ticketFor($request, $id)) {
            return $this->notFound();
        }

        return response()->json(['status' => 409, 'message' => 'Ticket đã vào workflow nên không được xóa cứng; hãy hủy hoặc đóng ticket', 'data' => null], 409);
    }

    private function ticketFor(Request $request, int $id, bool $managerOnly = false): ?object
    {
        $manager = $this->canManage($request);
        if ($managerOnly && ! $manager) {
            return null;
        }

        return DB::table('service_tickets as t')
            ->leftJoin('service_categories as c', function ($join): void {
                $join->on('c.id', '=', 't.category_id')->on('c.tenant_id', '=', 't.tenant_id');
            })
            ->leftJoin('employees as e', function ($join): void {
                $join->on('e.id', '=', 't.requester_id')->on('e.tenant_id', '=', 't.tenant_id');
            })
            ->where('t.tenant_id', TenantContext::id())->where('t.id', $id)
            ->when(! $manager, fn ($query) => $query->where('t.requester_id', $this->actor($request)))
            ->first(['t.*', 'c.category_code', 'c.category_name', 'e.employee_code', 'e.full_name as requester_name']);
    }

    private function assertCategory(int $id): void
    {
        abort_unless(DB::table('service_categories')->where('tenant_id', TenantContext::id())->where('id', $id)
            ->whereRaw("upper(coalesce(status, 'ACTIVE')) = 'ACTIVE'")->exists(), 422, 'Danh mục dịch vụ không hợp lệ');
    }

    private function assertTransition(string $from, string $to): void
    {
        $from = strtoupper($from);
        $allowed = [
            'PENDING' => ['PENDING', 'IN_PROGRESS', 'CANCELLED'], 'OPEN' => ['OPEN', 'IN_PROGRESS', 'CANCELLED'],
            'IN_PROGRESS' => ['IN_PROGRESS', 'RESOLVED', 'CLOSED', 'CANCELLED'],
            'RESOLVED' => ['RESOLVED', 'IN_PROGRESS', 'CLOSED'], 'CLOSED' => ['CLOSED'], 'CANCELLED' => ['CANCELLED'],
        ];
        if (! in_array(strtoupper($to), $allowed[$from] ?? [$from], true)) {
            throw ValidationException::withMessages(['status' => ["Không thể chuyển trạng thái {$from} sang {$to}"]]);
        }
    }

    private function appendUpdate(int $ticketId, Request $request, string $action, ?string $old, ?string $new, ?string $comment): void
    {
        DB::table('service_ticket_updates')->insert(TenantContext::stamp([
            'ticket_id' => $ticketId, 'created_by' => $this->actor($request), 'action_type' => $action,
            'old_status' => $old, 'new_status' => $new, 'comment' => $comment,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function canManage(Request $request): bool
    {
        return AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'communications.manage');
    }

    private function actor(Request $request): int
    {
        return (int) $request->attributes->get('auth_employee_id');
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => 'Không tìm thấy ticket', 'data' => null], 404);
    }
}
