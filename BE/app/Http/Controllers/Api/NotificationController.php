<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Thông báo cá nhân (chuông). Mỗi nhân viên chỉ đọc/ghi thông báo của CHÍNH MÌNH
 * (receiver_id = NV đang đăng nhập) — controller tự khoá theo auth_employee_id.
 */
class NotificationController extends Controller
{
    /** GET /notifications — danh sách thông báo của tôi (chưa đọc lên trước). */
    public function index(Request $request): JsonResponse
    {
        $me = $this->me($request);
        if (! $me) {
            return $this->ok(['items' => [], 'unread_count' => 0], 'Thông báo');
        }

        $rows = DB::table('notifications')
            ->where('tenant_id', TenantContext::id())
            ->where('receiver_id', $me)
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $unread = DB::table('notifications')
            ->where('tenant_id', TenantContext::id())
            ->where('receiver_id', $me)
            ->whereNull('read_at')
            ->count();

        return $this->ok(['items' => $rows, 'unread_count' => $unread], 'Thông báo');
    }

    /** POST /notifications/{id}/read — đánh dấu đã đọc 1 thông báo của tôi. */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $me = $this->me($request);
        $n = DB::table('notifications')->where('id', $id)
            ->where('tenant_id', TenantContext::id())->first();
        if (! $n || (int) $n->receiver_id !== $me) {
            return response()->json(['status' => 404, 'message' => 'Không tìm thấy', 'data' => null], 404);
        }
        DB::table('notifications')->where('id', $id)->update(['read_at' => now(), 'updated_at' => now()]);

        return $this->ok(['id' => $id], 'Đã đọc');
    }

    /** POST /notifications/read-all — đánh dấu đã đọc tất cả của tôi. */
    public function markAllRead(Request $request): JsonResponse
    {
        $me = $this->me($request);
        if ($me) {
            DB::table('notifications')->where('tenant_id', TenantContext::id())
                ->where('receiver_id', $me)->whereNull('read_at')
                ->update(['read_at' => now(), 'updated_at' => now()]);
        }

        return $this->ok(['ok' => true], 'Đã đọc tất cả');
    }

    private function me(Request $request): int
    {
        return (int) ($request->attributes->get('auth_employee_id') ?? 0);
    }

    private function ok($data, string $msg): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $msg, 'data' => $data]);
    }
}
