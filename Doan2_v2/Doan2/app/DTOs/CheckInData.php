<?php

namespace App\DTOs;

use Illuminate\Http\Request;

/**
 * Data Transfer Object: Đóng gói dữ liệu check-in giữa các tầng.
 * Sử dụng readonly class (PHP 8.2+) để đảm bảo immutability.
 * DTO không chứa business logic, chỉ là "data carrier".
 */
readonly class CheckInData
{
    public function __construct(
        public int $employeeId,
        public string $action,        // 'CHECK_IN' | 'CHECK_OUT'
        public string $source,        // 'API' | 'FACE_ID' | 'QR_CODE' | 'MANUAL'
        public ?string $deviceId,
        public ?string $locationCode,
        public ?string $ipAddress,
        public ?array $metadata,
        public string $checkedAt,     // ISO 8601 timestamp string
    ) {}

    /**
     * Factory method tạo DTO từ HTTP Request.
     * Tách biệt trách nhiệm: Controller không cần biết cấu trúc DTO.
     */
    public static function fromRequest(
        Request $request,
        string $action
    ): self {
        return new self(
            employeeId: (int) $request->input('employee_id'),
            action: $action,
            source: $request->input('source', 'API'),
            deviceId: $request->input('device_id'),
            locationCode: $request->input('location_code'),
            ipAddress: $request->ip(),
            metadata: $request->input('metadata'),
            checkedAt: now()->toIso8601String(),
        );
    }

    /** Chuyển thành array để serialize vào Redis / Queue payload */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'action' => $this->action,
            'source' => $this->source,
            'device_id' => $this->deviceId,
            'location_code' => $this->locationCode,
            'ip_address' => $this->ipAddress,
            'metadata' => $this->metadata,
            'checked_at' => $this->checkedAt,
        ];
    }

    /** Khôi phục DTO từ array (dùng trong Job khi deserialize từ Queue payload) */
    public static function fromArray(array $data): self
    {
        return new self(
            employeeId: (int) $data['employee_id'],
            action: $data['action'],
            source: $data['source'] ?? 'API',
            deviceId: $data['device_id'] ?? null,
            locationCode: $data['location_code'] ?? null,
            ipAddress: $data['ip_address'] ?? null,
            metadata: $data['metadata'] ?? null,
            checkedAt: $data['checked_at'],
        );
    }
}
