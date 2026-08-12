<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pusher\Pusher;

class AttendanceRealtimeController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        $access = (array) $request->attributes->get('access', []);
        $employeeId = (int) $request->attributes->get('auth_employee_id');
        $canManage = ! empty($access['full']) || in_array('time', $access['modules'] ?? [], true);
        $legalEntityId = (int) TenantContext::legalEntityId();
        if (! empty($access['full']) && $request->filled('legal_entity_id')) {
            $requested = (int) $request->query('legal_entity_id');
            if (TenantContext::ownsRow('legal_entities', $requested)) {
                $legalEntityId = $requested;
            }
        }

        return response()->json([
            'status' => 200,
            'message' => 'Attendance realtime configuration',
            'data' => [
                'enabled' => config('broadcasting.default') === 'reverb' && (string) config('broadcasting.connections.reverb.key') !== '',
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('hrm.realtime.host'),
                'port' => (int) config('hrm.realtime.port', 443),
                'scheme' => config('hrm.realtime.scheme', 'https'),
                'channel' => $canManage
                    ? 'attendance.tenant.'.TenantContext::id().'.entity.'.$legalEntityId
                    : 'attendance.employee.'.$employeeId,
            ],
        ]);
    }

    public function authenticate(Request $request): JsonResponse
    {
        $channel = (string) $request->input('channel_name');
        $socketId = (string) $request->input('socket_id');
        if ($channel === '' || $socketId === '' || ! $this->canSubscribe($request, $channel)) {
            return response()->json(['status' => 403, 'message' => 'Không có quyền subscribe kênh realtime.', 'data' => null], 403);
        }

        $connection = config('broadcasting.connections.reverb');
        $pusher = new Pusher(
            (string) $connection['key'],
            (string) $connection['secret'],
            (string) $connection['app_id'],
            (array) ($connection['options'] ?? [])
        );

        $response = $pusher->authorizeChannel($channel, $socketId);

        return response()->json(json_decode($response, true, 512, JSON_THROW_ON_ERROR));
    }

    private function canSubscribe(Request $request, string $channel): bool
    {
        $channel = preg_replace('/^private-/', '', $channel);
        $employeeId = (int) $request->attributes->get('auth_employee_id');
        if ($channel === 'attendance.employee.'.$employeeId) {
            return true;
        }

        $access = (array) $request->attributes->get('access', []);
        $canManage = ! empty($access['full']) || in_array('time', $access['modules'] ?? [], true);
        if (! $canManage) {
            return false;
        }

        $tenantPrefix = 'attendance.tenant.'.TenantContext::id();
        if ($channel === $tenantPrefix.'.all') {
            return true;
        }

        if (preg_match('/^'.preg_quote($tenantPrefix, '/').'\.entity\.(\d+)$/', $channel, $matches)) {
            if ((int) $matches[1] === (int) TenantContext::legalEntityId()) {
                return true;
            }

            return ! empty($access['full']) && TenantContext::ownsRow('legal_entities', (int) $matches[1]);
        }

        return false;
    }
}
