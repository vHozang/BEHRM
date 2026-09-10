<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ghi thông báo bền (bảng `notifications`) gửi tới 1 nhân viên. Dùng cho các sự
 * kiện nghiệp vụ: mời phủ ca, kết quả phủ ca, đổi ca, duyệt nghỉ/tăng ca... FE
 * (chuông) đọc qua NotificationController. Lỗi ghi thông báo KHÔNG được làm hỏng
 * hành động chính → luôn bọc try/catch.
 */
class Notifier
{
    public static function notify(
        ?int $receiverId,
        string $title,
        string $message,
        ?string $refType = null,
        $refId = null,
        array $meta = [],
        ?int $senderId = null
    ): void {
        if (! $receiverId) {
            return;
        }
        try {
            DB::table('notifications')->insert([
                'tenant_id' => TenantContext::id(),
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'title' => $title,
                'message' => $message,
                'priority' => $meta['priority'] ?? 'normal',
                'reference_type' => $refType,
                'reference_id' => $refId,
                'read_at' => null,
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notifier failed: ' . $e->getMessage());
        }
    }

    /** Gửi cùng một thông báo tới nhiều người nhận. */
    public static function notifyMany(array $receiverIds, string $title, string $message, ?string $refType = null, $refId = null, array $meta = [], ?int $senderId = null): void
    {
        foreach (array_unique(array_filter($receiverIds)) as $rid) {
            self::notify((int) $rid, $title, $message, $refType, $refId, $meta, $senderId);
        }
    }
}
