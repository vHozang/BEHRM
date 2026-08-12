<?php

namespace App\Services;

use App\Events\AttendanceChanged;
use App\Models\Attendance;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AttendanceChangePublisher
{
    /** @var array<int, string> */
    private static array $pendingTypes = [];

    public function publish(Attendance $attendance, string $changeType): void
    {
        $attendanceId = (int) $attendance->id;
        if (isset(self::$pendingTypes[$attendanceId])) {
            self::$pendingTypes[$attendanceId] = $changeType;

            return;
        }
        self::$pendingTypes[$attendanceId] = $changeType;

        $payload = [
            'tenant_id' => (int) $attendance->tenant_id,
            'legal_entity_id' => $attendance->legal_entity_id ? (int) $attendance->legal_entity_id : null,
            'attendance_id' => (int) $attendance->id,
            'employee_id' => (int) $attendance->employee_id,
            'work_date' => $attendance->work_date?->format('Y-m-d') ?? (string) $attendance->work_date,
            'change_type' => $changeType,
            'updated_at' => now()->toIso8601String(),
        ];

        DB::afterCommit(function () use ($payload, $attendanceId): void {
            $payload['change_type'] = self::$pendingTypes[$attendanceId] ?? $payload['change_type'];
            unset(self::$pendingTypes[$attendanceId]);
            $this->commit($payload);
        });
        DB::afterRollBack(function () use ($attendanceId): void {
            unset(self::$pendingTypes[$attendanceId]);
        });
    }

    public function publishById(int $attendanceId, string $changeType): void
    {
        $attendance = Attendance::withoutTenantScope()->find($attendanceId);
        if ($attendance) {
            $this->publish($attendance, $changeType);
        }
    }

    public function versionToken(int $tenantId, ?int $legalEntityId = null): string
    {
        try {
            $cache = $this->cache();
            $keys = [$this->versionKey($tenantId, null)];
            if ($legalEntityId) {
                $keys[] = $this->versionKey($tenantId, $legalEntityId);
            }
            $versions = $cache->many($keys);
            $global = (int) ($versions[$keys[0]] ?? 1);
            $entity = $legalEntityId ? (int) ($versions[$keys[1]] ?? 1) : 0;

            return "{$global}.{$entity}";
        } catch (\Throwable) {
            return '1.0';
        }
    }

    public function cache(): CacheRepository
    {
        return Cache::store((string) config('hrm.attendance.overview_cache_store', config('cache.default')));
    }

    /** @param array<string, mixed> $payload */
    private function commit(array $payload): void
    {
        try {
            $this->bumpVersion((int) $payload['tenant_id'], $payload['legal_entity_id']);
            $payload['version'] = $this->versionToken((int) $payload['tenant_id'], $payload['legal_entity_id']);

            if (! Schema::hasTable('attendance_change_events')) {
                return;
            }

            $id = DB::table('attendance_change_events')->insertGetId([
                'tenant_id' => $payload['tenant_id'],
                'legal_entity_id' => $payload['legal_entity_id'],
                'attendance_id' => $payload['attendance_id'],
                'employee_id' => $payload['employee_id'],
                'work_date' => $payload['work_date'] ?: null,
                'change_type' => $payload['change_type'],
                'created_at' => now(),
            ]);
            $payload['cursor'] = self::encodeCursor($id);

            event(new AttendanceChanged($payload));
        } catch (\Throwable $exception) {
            Log::warning('Attendance change publication failed', [
                'attendance_id' => $payload['attendance_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function bumpVersion(int $tenantId, ?int $legalEntityId): void
    {
        try {
            $cache = $this->cache();
            foreach ([$this->versionKey($tenantId, null), $legalEntityId ? $this->versionKey($tenantId, $legalEntityId) : null] as $key) {
                if (! $key) {
                    continue;
                }
                $cache->add($key, 1, now()->addDays(30));
                $cache->increment($key);
            }
        } catch (\Throwable $exception) {
            Log::debug('Attendance cache version bump skipped', ['error' => $exception->getMessage()]);
        }
    }

    private function versionKey(int $tenantId, ?int $legalEntityId): string
    {
        return 'attendance:version:t'.$tenantId.':e'.($legalEntityId ?: 'all');
    }

    public static function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public static function decodeCursor(?string $cursor): int
    {
        if (! $cursor) {
            return 0;
        }

        try {
            $padding = strlen($cursor) % 4;
            $raw = base64_decode(strtr($cursor.($padding ? str_repeat('=', 4 - $padding) : ''), '-_', '+/'), true);
            $decoded = json_decode((string) $raw, true, 8, JSON_THROW_ON_ERROR);

            return max(0, (int) ($decoded['id'] ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }
}
