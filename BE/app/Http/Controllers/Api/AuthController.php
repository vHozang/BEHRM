<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrganizationChartRepository;
use App\Services\RefreshTokenService;
use App\Support\AccessControl;
use App\Support\HrmConfig;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly RefreshTokenService $refreshTokens) {}

    public function login(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'company_email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $employee = DB::table('employees')
            ->where('company_email', $payload['company_email'])
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->first();

        if (! $employee || ! Hash::check($payload['password'], $employee->password_hash ?? '')) {
            return response()->json([
                'status' => 401,
                'message' => 'Invalid credentials',
                'data' => null,
            ], 401);
        }

        $familyId = Str::uuid()->toString();
        $accessToken = $this->issueToken((int) $employee->id, $familyId);
        $refreshToken = $this->refreshTokens->issue(
            (int) $employee->id,
            (int) $employee->tenant_id,
            $familyId,
            $request,
        );

        $access = AccessControl::forEmployee((int) $employee->id, ! empty($employee->is_super_admin));

        unset($employee->password_hash); // không bao giờ trả hash về client
        $employee->roles = $access['roles'] ?? [];

        $response = response()->json([
            'status' => 200,
            'message' => 'Login successful',
            'data' => [
                'access_token' => $accessToken['token'],
                'token_type' => 'Bearer',
                'expires_in' => (int) env('JWT_TTL', 3600),
                'expires_at' => $accessToken['expires_at']->toIso8601String(),
                'employee' => $employee,
                'access' => $access,
            ],
        ]);

        return $response->withCookie($this->refreshCookie($refreshToken['token']));
    }

    public function me(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('auth_employee');
        $access = $request->attributes->get('access')
            ?? AccessControl::forEmployee((int) $employee['id'], ! empty($employee['is_super_admin']));
        $employee['roles'] = $access['roles'] ?? [];

        return response()->json([
            'status' => 200,
            'message' => 'Authenticated employee',
            'data' => $employee,
            'access' => $access,
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        if (! $this->isTrustedBrowserRequest($request)) {
            return response()->json(['status' => 403, 'message' => 'Untrusted request origin', 'data' => null], 403);
        }

        $cookieName = (string) config('hrm.auth.refresh_cookie', 'hrm_refresh');
        $rawRefreshToken = $request->cookie($cookieName);
        $rotated = $rawRefreshToken ? $this->refreshTokens->rotate($rawRefreshToken, $request) : null;

        if ($rotated && $rotated['status'] === 'in_progress') {
            return response()->json([
                'status' => 409,
                'code' => 'REFRESH_IN_PROGRESS',
                'message' => 'Another tab is refreshing this session',
                'data' => [
                    'retry_after_ms' => (int) ($rotated['retry_after_ms'] ?? 500),
                ],
            ], 409)->header('Retry-After', '1');
        }

        if ($rotated && $rotated['status'] !== 'ok') {
            return response()->json([
                'status' => 401,
                'message' => $rotated['status'] === 'reused'
                    ? 'Refresh token reuse detected; session revoked'
                    : 'Invalid or expired refresh token',
                'data' => null,
            ], 401)->withCookie($this->forgetRefreshCookie());
        }

        // One-release compatibility: a still-valid legacy access token may
        // bootstrap a refresh-token family once.
        if (! $rotated) {
            $bearer = $request->bearerToken();
            $accessRow = $bearer ? DB::table('api_tokens')
                ->where('token_hash', hash('sha256', $bearer))
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first() : null;

            if (! $accessRow || $accessRow->family_id !== null) {
                return response()->json([
                    'status' => 401,
                    'message' => 'Refresh token required; legacy bootstrap is no longer available for this access token',
                    'data' => null,
                ], 401)->withCookie($this->forgetRefreshCookie());
            }

            $familyId = Str::uuid()->toString();
            DB::table('api_tokens')->where('id', $accessRow->id)->update([
                'family_id' => $familyId,
                'updated_at' => now(),
            ]);
            $legacyRefresh = $this->refreshTokens->issue(
                (int) $accessRow->employee_id,
                (int) $accessRow->tenant_id,
                $familyId,
                $request,
            );
            $rotated = [
                'status' => 'ok',
                'token' => $legacyRefresh['token'],
                'family_id' => $familyId,
                'employee_id' => (int) $accessRow->employee_id,
                'tenant_id' => (int) $accessRow->tenant_id,
            ];
        }

        $employee = DB::table('employees')
            ->where('id', $rotated['employee_id'])
            ->where('tenant_id', $rotated['tenant_id'])
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->first();
        if (! $employee) {
            $this->refreshTokens->revokeFamily((string) $rotated['family_id']);

            return response()->json(['status' => 401, 'message' => 'Employee not found', 'data' => null], 401)
                ->withCookie($this->forgetRefreshCookie());
        }

        $token = $this->issueToken((int) $employee->id, (string) $rotated['family_id']);

        $response = response()->json([
            'status' => 200,
            'message' => 'Token refreshed',
            'data' => [
                'access_token' => $token['token'],
                'token_type' => 'Bearer',
                'expires_in' => (int) env('JWT_TTL', 3600),
                'expires_at' => $token['expires_at']->toIso8601String(),
            ],
        ]);

        return $response->withCookie($this->refreshCookie((string) $rotated['token']));
    }

    public function logout(Request $request): JsonResponse
    {
        if (! $this->isTrustedBrowserRequest($request)) {
            return response()->json(['status' => 403, 'message' => 'Untrusted request origin', 'data' => null], 403);
        }

        $cookieName = (string) config('hrm.auth.refresh_cookie', 'hrm_refresh');
        $familyId = $this->refreshTokens->revokeToken($request->cookie($cookieName));
        $bearer = $request->bearerToken();
        if ($bearer) {
            $accessRow = DB::table('api_tokens')->where('token_hash', hash('sha256', $bearer))->first();
            if ($accessRow?->family_id && $familyId === null) {
                $this->refreshTokens->revokeFamily((string) $accessRow->family_id);
            } else {
                DB::table('api_tokens')->where('id', $accessRow?->id)->delete();
            }
        }

        return response()->json([
            'status' => 200,
            'message' => 'Logged out',
            'data' => null,
        ])->withCookie($this->forgetRefreshCookie());
    }

    public function uiPreferences(): JsonResponse
    {
        $separator = (string) HrmConfig::get('display.money_group_separator', '.');

        return response()->json([
            'status' => 200,
            'message' => 'UI preferences',
            'data' => [
                'money_group_separator' => in_array($separator, ['.', ','], true) ? $separator : '.',
                'weekly_rest_weekday' => max(0, min(6, (int) HrmConfig::get('attendance.weekly_rest_weekday', 0))),
            ],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                'different:current_password',
                Password::min(8)->letters()->numbers(),
            ],
        ]);

        $employee = $request->attributes->get('auth_employee');
        $passwordHash = DB::table('employees')
            ->where('id', $employee['id'])
            ->value('password_hash');

        if (! $passwordHash || ! Hash::check($payload['current_password'], $passwordHash)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mật khẩu hiện tại không chính xác.'],
            ]);
        }

        DB::transaction(function () use ($employee, $payload): void {
            DB::table('employees')->where('id', $employee['id'])->update([
                'password_hash' => Hash::make($payload['password']),
                'updated_at' => now(),
            ]);

            // Revoke every active session after a credential change.
            DB::table('api_tokens')->where('employee_id', $employee['id'])->delete();
            $this->refreshTokens->revokeEmployee((int) $employee['id']);
        });

        return response()->json([
            'status' => 200,
            'message' => 'Password changed successfully',
            'data' => null,
        ]);
    }

    public function activityLogs(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 10), 1), 50);

        // Recent real change history from the audit trail (tenant-scoped).
        $items = DB::table('audit_logs')
            ->leftJoin('employees', 'employees.id', '=', 'audit_logs.actor_employee_id')
            ->where('audit_logs.tenant_id', TenantContext::id())
            ->orderByDesc('audit_logs.created_at')
            ->limit($limit)
            ->get([
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.table_name',
                'audit_logs.record_id',
                'audit_logs.created_at as at',
                'employees.full_name as actor_name',
            ]);

        return response()->json([
            'status' => 200,
            'message' => 'Activity logs list',
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $limit,
                    'total' => $items->count(),
                    'last_page' => 1,
                ],
            ],
        ]);
    }

    public function hierarchy(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('auth_employee');
        $scopeIds = [];

        if ($employee) {
            $repo = new OrganizationChartRepository;
            $tree = $repo->getTree((int) $employee['id']);
            // $tree includes the employee themselves, and all subordinates.
            // We just need the IDs.
            $scopeIds = $tree->pluck('id')->toArray();
        }

        return response()->json([
            'status' => 200,
            'message' => 'Hierarchy context',
            'data' => [
                'employee' => $employee,
                'scope_employee_ids' => $scopeIds,
            ],
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $payload = $request->validate(['company_email' => ['required', 'email']]);
        $employee = DB::table('employees')->where('company_email', $payload['company_email'])->first();

        if ($employee) {
            $token = Str::random(64);
            DB::table('password_reset_requests')->insert([
                'employee_id' => $employee->id,
                'company_email' => $payload['company_email'],
                'token' => $token,
                'expires_at' => now()->addHour(),
                'tenant_id' => $employee->tenant_id ?? TenantContext::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Gửi email đặt lại mật khẩu. Driver 'log' (dev) ghi vào laravel.log;
            // production cấu hình SMTP là gửi thật — KHÔNG đổi code. try/catch để
            // lỗi mail không làm hỏng phản hồi (vẫn trả thông báo trung lập).
            $resetUrl = rtrim((string) config('app.frontend_url'), '/').'/reset-password?token='.$token;
            try {
                Mail::raw(
                    "Xin chào,\n\nCó yêu cầu đặt lại mật khẩu cho tài khoản {$payload['company_email']}.\n"
                    ."Nhấn liên kết sau để đặt lại (hết hạn sau 1 giờ):\n{$resetUrl}\n\n"
                    ."Nếu không phải bạn, hãy bỏ qua email này.\n\n— Hệ thống HRM",
                    fn ($m) => $m->to($payload['company_email'])->subject('Đặt lại mật khẩu HRM')
                );
            } catch (\Throwable $e) {
                Log::warning('Gửi email reset password thất bại: '.$e->getMessage());
            }
        }

        return response()->json([
            'status' => 200,
            'message' => 'If the email exists, a reset request has been created',
            'data' => null,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ]);

        $passwordChanged = DB::transaction(function () use ($payload): bool {
            $reset = DB::table('password_reset_requests')
                ->where('token', $payload['token'])
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $reset) {
                return false;
            }

            DB::table('employees')->where('id', $reset->employee_id)->update([
                'password_hash' => Hash::make($payload['password']),
                'updated_at' => now(),
            ]);
            DB::table('password_reset_requests')->where('id', $reset->id)->update([
                'used_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('api_tokens')->where('employee_id', $reset->employee_id)->delete();
            $this->refreshTokens->revokeEmployee((int) $reset->employee_id);

            return true;
        });

        if (! $passwordChanged) {
            return response()->json([
                'status' => 422,
                'message' => 'Invalid reset token',
                'data' => null,
            ], 422);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Password reset successful',
            'data' => null,
        ]);
    }

    /** @return array{token: string, expires_at: \Illuminate\Support\Carbon} */
    private function issueToken(int $employeeId, ?string $familyId = null): array
    {
        $token = rtrim(strtr(base64_encode(json_encode([
            'iss' => env('JWT_ISSUER', 'hrm-system'),
            'sub' => $employeeId,
            'iat' => time(),
            'jti' => Str::uuid()->toString(),
        ])), '+/', '-_'), '=').'.'.Str::random(80);

        // Derive tenant from the employee the token is issued for. Login runs
        // before HrmAuth resolves TenantContext, so we cannot rely on it here.
        $tenantId = DB::table('employees')->where('id', $employeeId)->value('tenant_id')
            ?? TenantContext::id();

        $expiresAt = now()->addSeconds((int) env('JWT_TTL', 3600));
        DB::table('api_tokens')->insert([
            'employee_id' => $employeeId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'family_id' => $familyId,
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    private function refreshCookie(string $token): \Symfony\Component\HttpFoundation\Cookie
    {
        $secure = config('hrm.auth.refresh_cookie_secure');
        if ($secure === null) {
            $secure = app()->environment('production');
        }

        return Cookie::make(
            (string) config('hrm.auth.refresh_cookie', 'hrm_refresh'),
            $token,
            (int) config('hrm.auth.refresh_days', 30) * 1440,
            '/api/v1/auth',
            null,
            (bool) $secure,
            true,
            false,
            'lax',
        );
    }

    private function forgetRefreshCookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::forget(
            (string) config('hrm.auth.refresh_cookie', 'hrm_refresh'),
            '/api/v1/auth',
        );
    }

    private function isTrustedBrowserRequest(Request $request): bool
    {
        $source = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (! $source) {
            return ! app()->environment('production');
        }

        $allowed = array_merge(
            (array) config('hrm.auth.trusted_origins', []),
            array_filter([(string) config('app.url'), (string) config('app.frontend_url')]),
        );
        $sourceOrigin = $this->normalizeOrigin($source);

        return $sourceOrigin !== null && collect($allowed)
            ->map(fn ($origin) => $this->normalizeOrigin((string) $origin))
            ->filter()
            ->contains($sourceOrigin);
    }

    private function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));
    }
}
