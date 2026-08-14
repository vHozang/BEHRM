<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class RefreshTokenService
{
    /** @return array{token: string, id: int, family_id: string, expires_at: \Illuminate\Support\Carbon} */
    public function issue(
        int $employeeId,
        int $tenantId,
        string $familyId,
        Request $request,
        int $rotationNumber = 0,
    ): array
    {
        $token = Str::random(96);
        $expiresAt = now()->addDays((int) config('hrm.auth.refresh_days', 30));
        $id = DB::table('api_refresh_tokens')->insertGetId([
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'family_id' => $familyId,
            'rotation_number' => $rotationNumber,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'created_ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'token' => $token,
            'id' => $id,
            'family_id' => $familyId,
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array{status: string, token?: string, family_id?: string, employee_id?: int, tenant_id?: int, expires_at?: \Illuminate\Support\Carbon, retry_after_ms?: int} */
    public function rotate(string $token, Request $request): array
    {
        return DB::transaction(function () use ($token, $request): array {
            $row = DB::table('api_refresh_tokens')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $row || Carbon::parse($row->expires_at)->lessThanOrEqualTo(now())) {
                return ['status' => 'invalid'];
            }

            if ($row->revoked_at !== null) {
                return ['status' => 'revoked'];
            }

            if ($row->rotated_at !== null) {
                $graceSeconds = max(0, (int) config('hrm.auth.refresh_rotation_grace_seconds', 5));
                $graceEndsAt = Carbon::parse($row->rotated_at)->addSeconds($graceSeconds);
                if ($graceSeconds > 0 && now()->lessThan($graceEndsAt)) {
                    return [
                        'status' => 'in_progress',
                        'retry_after_ms' => (int) max(100, now()->diffInMilliseconds($graceEndsAt, false)),
                    ];
                }

                DB::table('api_refresh_tokens')->where('id', $row->id)->update([
                    'reuse_detected_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('api_refresh_tokens')
                    ->where('family_id', $row->family_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
                DB::table('api_tokens')->where('family_id', $row->family_id)->delete();

                return ['status' => 'reused'];
            }

            $next = $this->issue(
                (int) $row->employee_id,
                (int) $row->tenant_id,
                (string) $row->family_id,
                $request,
                (int) $row->rotation_number + 1,
            );

            DB::table('api_refresh_tokens')->where('id', $row->id)->update([
                'rotated_at' => now(),
                'last_used_at' => now(),
                'replaced_by_id' => $next['id'],
                'updated_at' => now(),
            ]);

            return [
                'status' => 'ok',
                'token' => $next['token'],
                'family_id' => (string) $row->family_id,
                'employee_id' => (int) $row->employee_id,
                'tenant_id' => (int) $row->tenant_id,
                'expires_at' => $next['expires_at'],
            ];
        });
    }

    public function revokeToken(?string $token): ?string
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $row = DB::table('api_refresh_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->first(['family_id']);

        if (! $row) {
            return null;
        }

        $this->revokeFamily((string) $row->family_id);

        return (string) $row->family_id;
    }

    public function revokeFamily(string $familyId): void
    {
        DB::transaction(function () use ($familyId): void {
            DB::table('api_refresh_tokens')
                ->where('family_id', $familyId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('api_tokens')->where('family_id', $familyId)->delete();
        });
    }

    public function revokeEmployee(int $employeeId): void
    {
        DB::transaction(function () use ($employeeId): void {
            DB::table('api_refresh_tokens')
                ->where('employee_id', $employeeId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('api_tokens')->where('employee_id', $employeeId)->delete();
        });
    }
}
