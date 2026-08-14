<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pusher\Pusher;

class AttendanceRealtimeController extends Controller
{
    public function __construct(private readonly AttendanceAccess $attendanceAccess) {}

    public function config(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'message' => 'Attendance realtime configuration',
            'data' => [
                'enabled' => config('broadcasting.default') === 'reverb' && (string) config('broadcasting.connections.reverb.key') !== '',
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('hrm.realtime.host'),
                'port' => (int) config('hrm.realtime.port', 443),
                'scheme' => config('hrm.realtime.scheme', 'https'),
                'channels' => $this->attendanceAccess->realtimeChannels($request),
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
        $channel = (string) preg_replace('/^private-/', '', $channel);

        return in_array($channel, $this->attendanceAccess->realtimeChannels($request), true);
    }
}
