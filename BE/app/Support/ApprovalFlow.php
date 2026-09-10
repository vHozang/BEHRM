<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Luồng DUYỆT NHIỀU CẤP (cấu hình được) dùng chung cho đơn từ (nghỉ phép, tăng
 * ca, điều chỉnh công…). Tiến trình lưu trong cột meta của đơn — không cần bảng
 * riêng. Mặc định 1 cấp (HR) → hành vi y như cũ; tenant đặt chuỗi nhiều cấp
 * (vd ['MANAGER','DEPT_HEAD','HR']) qua HrmConfig để bật duyệt nhiều cấp.
 *
 * meta.approval = {
 *   chain: ['MANAGER','HR'], current: 0,
 *   steps: [ {role, status:PENDING|APPROVED|REJECTED, approver_id, at, comment}, ... ]
 * }
 */
class ApprovalFlow
{
    /** Chuỗi cấp duyệt cho một loại đơn (approval.{type}_chain). */
    public static function chainFor(string $type): array
    {
        $chain = HrmConfig::get("approval.{$type}_chain", ['HR']);
        if (! is_array($chain)) {
            $chain = preg_split('/[,\s]+/', (string) $chain, -1, PREG_SPLIT_NO_EMPTY) ?: ['HR'];
        }
        $chain = array_values(array_filter(array_map(fn ($r) => strtoupper(trim((string) $r)), $chain)));

        return $chain ?: ['HR'];
    }

    /** Khởi tạo tiến trình duyệt trong meta nếu chưa có. */
    public static function ensure(array $meta, string $type): array
    {
        if (isset($meta['approval']['steps']) && is_array($meta['approval']['steps'])) {
            return $meta;
        }
        $chain = self::chainFor($type);
        $meta['approval'] = [
            'chain' => $chain,
            'current' => 0,
            'steps' => array_map(fn ($role) => [
                'role' => $role, 'status' => 'PENDING',
                'approver_id' => null, 'at' => null, 'comment' => null,
            ], $chain),
        ];

        return $meta;
    }

    /**
     * Duyệt cấp hiện tại. Trả [meta, finalApproved].
     * finalApproved = true khi đã qua hết các cấp.
     */
    public static function approveStep(array $meta, $approverId, ?string $comment = null): array
    {
        $a = &$meta['approval'];
        $i = (int) ($a['current'] ?? 0);
        if (isset($a['steps'][$i])) {
            $a['steps'][$i]['status'] = 'APPROVED';
            $a['steps'][$i]['approver_id'] = $approverId;
            $a['steps'][$i]['at'] = now()->toIso8601String();
            $a['steps'][$i]['comment'] = $comment;
            $a['current'] = $i + 1;
        }
        $final = (int) $a['current'] >= count($a['steps']);

        return [$meta, $final];
    }

    /** Từ chối ở cấp hiện tại. */
    public static function rejectStep(array $meta, $approverId, ?string $comment = null): array
    {
        $a = &$meta['approval'];
        $i = (int) ($a['current'] ?? 0);
        if (isset($a['steps'][$i])) {
            $a['steps'][$i]['status'] = 'REJECTED';
            $a['steps'][$i]['approver_id'] = $approverId;
            $a['steps'][$i]['at'] = now()->toIso8601String();
            $a['steps'][$i]['comment'] = $comment;
        }

        return $meta;
    }

    /**
     * Người duyệt có ĐÚNG VAI để duyệt CẤP HIỆN TẠI không?
     * Trả null nếu được phép; ngược lại trả thông báo lỗi (để chặn 422/403).
     * - Super-admin hoặc role admin (code ADMIN / meta.is_admin) duyệt được MỌI cấp (override).
     * - Còn lại: phải giữ đúng role_code của cấp hiện tại.
     * - Không có cấp/role hoặc không xác định người duyệt → null (giữ hành vi cũ, không chặn).
     */
    public static function cannotApprove(array $meta, ?int $approverId): ?string
    {
        $role = self::currentRole($meta);
        if (! $role || ! $approverId) {
            return null;
        }

        $emp = DB::table('employees')->where('id', $approverId)->first(['is_super_admin']);
        if ($emp && ! empty($emp->is_super_admin)) {
            return null; // super-admin override
        }

        $roles = DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.employee_id', $approverId)
            ->get(['r.role_code', 'r.meta']);
        foreach ($roles as $r) {
            $code = strtoupper((string) $r->role_code);
            $rmeta = is_string($r->meta) ? (json_decode($r->meta, true) ?: []) : (array) ($r->meta ?? []);
            if ($code === 'ADMIN' || ! empty($rmeta['is_admin'])) {
                return null; // admin override
            }
            if ($code === $role) {
                return null; // đúng vai cấp này
            }
        }

        return "Bạn không giữ vai trò {$role} để duyệt cấp này.";
    }

    /** Nhãn cấp đang chờ duyệt (để hiển thị/thông báo). */
    public static function currentRole(array $meta): ?string
    {
        $a = $meta['approval'] ?? null;
        if (! $a) {
            return null;
        }
        $i = (int) ($a['current'] ?? 0);

        return $a['steps'][$i]['role'] ?? null;
    }

    /** Tổng số cấp + cấp hiện tại (1-based) để hiển thị "cấp X/Y". */
    public static function progress(array $meta): array
    {
        $a = $meta['approval'] ?? ['steps' => [], 'current' => 0];
        $total = count($a['steps'] ?? []);
        $done = min((int) ($a['current'] ?? 0), $total);

        return ['done' => $done, 'total' => $total];
    }
}
