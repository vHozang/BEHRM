<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrganizationChartRepository;
use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'company_email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $employee = DB::table('employees')->where('company_email', $payload['company_email'])->first();

        if (! $employee || ! Hash::check($payload['password'], $employee->password_hash ?? '')) {
            return response()->json([
                'status' => 401,
                'message' => 'Invalid credentials',
                'data' => null,
            ], 401);
        }

        $token = $this->issueToken((int) $employee->id);

        $access = AccessControl::forEmployee((int) $employee->id, ! empty($employee->is_super_admin));

        unset($employee->password_hash); // không bao giờ trả hash về client

        return response()->json([
            'status' => 200,
            'message' => 'Login successful',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => (int) env('JWT_TTL', 3600),
                'employee' => $employee,
                'access' => $access,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('auth_employee');
        $access = $request->attributes->get('access')
            ?? AccessControl::forEmployee((int) $employee['id'], ! empty($employee['is_super_admin']));

        return response()->json([
            'status' => 200,
            'message' => 'Authenticated employee',
            'data' => $employee,
            'access' => $access,
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('auth_employee');
        $token = $this->issueToken((int) $employee['id']);

        return response()->json([
            'status' => 200,
            'message' => 'Token refreshed',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => (int) env('JWT_TTL', 3600),
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

    private function issueToken(int $employeeId): string
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

        DB::table('api_tokens')->insert([
            'employee_id' => $employeeId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addSeconds((int) env('JWT_TTL', 3600)),
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
