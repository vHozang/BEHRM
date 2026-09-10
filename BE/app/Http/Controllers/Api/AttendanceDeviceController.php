<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Quản lý MÁY CHẤM CÔNG của từng công ty (đa-tenant). Mỗi công ty khai báo
 * thiết bị + chọn phương thức kết nối; hệ thống cấp device_token để bridge gửi
 * punch lên /internal/attendance/device-punch (xác thực theo thiết bị → đúng tenant).
 */
class AttendanceDeviceController extends Controller
{
    private const BRANDS = ['zkteco', 'wiseeye', 'hikvision', 'suprema', 'other'];

    private const PROTOCOLS = ['zk_pull', 'adms_push', 'cloud_api', 'file_import'];

    public function index(Request $request): JsonResponse
    {
        $devices = DB::table('attendance_devices')
            ->where('tenant_id', TenantContext::id())
            ->orderByDesc('id')
            ->get()
            ->map(fn ($d) => $this->present($d));

        return $this->ok($devices, 'Danh sách máy chấm công');
    }

    public function show(int $id): JsonResponse
    {
        $device = $this->find($id);
        if (! $device) {
            return $this->notFound();
        }

        return $this->ok($this->present($device), 'Chi tiết máy chấm công');
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'brand' => 'nullable|string',
            'protocol' => 'nullable|string',
            'location' => 'nullable|string',
        ], ['name.required' => 'Tên thiết bị là bắt buộc']);
        if ($v->fails()) {
            return $this->validationError($v->errors()->toArray());
        }

        $now = now();
        $id = DB::table('attendance_devices')->insertGetId([
            'tenant_id' => TenantContext::id(),
            'legal_entity_id' => TenantContext::legalEntityId(),
            'name' => $request->input('name'),
            'brand' => $this->oneOf($request->input('brand'), self::BRANDS, 'zkteco'),
            'protocol' => $this->oneOf($request->input('protocol'), self::PROTOCOLS, 'zk_pull'),
            'device_token' => $this->newToken(),
            'status' => 'ACTIVE',
            'location' => $request->input('location'),
            'meta' => json_encode($this->metaFrom($request), JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'status' => 201,
            'message' => 'Đã đăng ký máy chấm công',
            'data' => $this->present($this->find($id), true),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $device = $this->find($id);
        if (! $device) {
            return $this->notFound();
        }

        $payload = ['updated_at' => now()];
        foreach (['name', 'location'] as $f) {
            if ($request->filled($f)) {
                $payload[$f] = $request->input($f);
            }
        }
        if ($request->filled('brand')) {
            $payload['brand'] = $this->oneOf($request->input('brand'), self::BRANDS, $device->brand);
        }
        if ($request->filled('protocol')) {
            $payload['protocol'] = $this->oneOf($request->input('protocol'), self::PROTOCOLS, $device->protocol);
        }
        if ($request->filled('status')) {
            $payload['status'] = strtoupper($request->input('status')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';
        }
        if ($request->has('meta') || $request->hasAny(['ip', 'port', 'serial', 'api_key', 'column_mapping'])) {
            $existing = is_string($device->meta) ? (json_decode($device->meta, true) ?: []) : (array) ($device->meta ?? []);
            $payload['meta'] = json_encode(array_merge($existing, $this->metaFrom($request)), JSON_UNESCAPED_UNICODE);
        }

        DB::table('attendance_devices')->where('id', $id)->update($payload);

        return $this->ok($this->present($this->find($id)), 'Đã cập nhật thiết bị');
    }

    public function destroy(int $id): JsonResponse
    {
        $device = $this->find($id);
        if (! $device) {
            return $this->notFound();
        }
        DB::table('attendance_devices')->where('id', $id)->delete();

        return $this->ok(['id' => $id], 'Đã xoá thiết bị');
    }

    /** Cấp lại device_token (khi lộ token cũ). */
    public function rotateToken(int $id): JsonResponse
    {
        $device = $this->find($id);
        if (! $device) {
            return $this->notFound();
        }
        DB::table('attendance_devices')->where('id', $id)->update([
            'device_token' => $this->newToken(),
            'updated_at' => now(),
        ]);

        return $this->ok($this->present($this->find($id), true), 'Đã cấp lại token thiết bị');
    }

    // ── Helpers ──────────────────────────────────────────

    private function metaFrom(Request $r): array
    {
        $meta = is_array($r->input('meta')) ? $r->input('meta') : [];
        foreach (['ip', 'port', 'serial', 'api_key', 'column_mapping'] as $k) {
            if ($r->filled($k)) {
                $meta[$k] = $r->input($k);
            }
        }

        return $meta;
    }

    private function present($d, bool $includeToken = false): array
    {
        $meta = is_string($d->meta) ? (json_decode($d->meta, true) ?: []) : (array) ($d->meta ?? []);
        unset($meta['api_key']);

        $data = [
            'id' => $d->id,
            'name' => $d->name,
            'brand' => $d->brand,
            'protocol' => $d->protocol,
            'protocol_label' => $this->protocolLabel($d->protocol),
            'status' => $d->status,
            'location' => $d->location,
            'has_device_token' => ! empty($d->device_token),
            'device_token_hint' => $d->device_token ? substr($d->device_token, -4) : null,
            'last_seen_at' => $d->last_seen_at,
            'meta' => $meta,
        ];

        if ($includeToken) {
            $data['device_token'] = $d->device_token;
        }

        return $data;
    }

    private function protocolLabel(string $p): string
    {
        return [
            'zk_pull' => 'Kéo dữ liệu qua LAN (ZKTeco/Wise Eye, TCP 4370)',
            'adms_push' => 'Máy tự đẩy (ADMS/Push)',
            'cloud_api' => 'API đám mây của hãng (Hikvision/Suprema…)',
            'file_import' => 'Nhập từ file Excel/CSV (phần mềm hãng)',
        ][$p] ?? $p;
    }

    private function oneOf(?string $v, array $allowed, string $default): string
    {
        $v = strtolower((string) $v);

        return in_array($v, $allowed, true) ? $v : $default;
    }

    private function newToken(): string
    {
        return 'dev_' . Str::random(48);
    }

    private function find(int $id)
    {
        return DB::table('attendance_devices')
            ->where('id', $id)
            ->where('tenant_id', TenantContext::id())
            ->first();
    }

    private function ok($data, string $msg): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $msg, 'data' => $data]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => 'Không tìm thấy thiết bị', 'data' => null], 404);
    }

    private function validationError(array $e): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $e]], 422);
    }
}
