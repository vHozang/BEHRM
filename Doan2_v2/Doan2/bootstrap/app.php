<?php

use App\Console\Commands\ImportLegacyAttendanceLeave;
use App\Console\Commands\ImportLegacyCommunications;
use App\Console\Commands\ImportLegacyContracts;
use App\Console\Commands\ImportLegacyEmployees;
use App\Console\Commands\ImportLegacyMasterData;
use App\Console\Commands\ImportLegacyPayroll;
use App\Console\Commands\ImportLegacyRecruitment;
use App\Console\Commands\ImportLegacyRequests;
use App\Console\Commands\VerifyLegacyImport;
use App\Http\Middleware\HrmAuth;
use App\Http\Middleware\ModuleAccess;
use App\Http\Middleware\PlatformAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ImportLegacyMasterData::class,
        ImportLegacyEmployees::class,
        ImportLegacyContracts::class,
        ImportLegacyRequests::class,
        ImportLegacyAttendanceLeave::class,
        ImportLegacyPayroll::class,
        ImportLegacyRecruitment::class,
        ImportLegacyCommunications::class,
        VerifyLegacyImport::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'hrm.auth' => HrmAuth::class,
            'module.access' => ModuleAccess::class,
            'platform.admin' => PlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());

        // Zero-trust: input rác (sai kiểu, quá dài) chạm tới query → Postgres ném
        // QueryException → mặc định là 500 kèm cả câu SQL (crash + lộ schema). Quy
        // về lỗi client sạch theo SQLSTATE, một chỗ áp cho MỌI endpoint:
        //   22xxx = data exception (sai kiểu/quá dài/chia 0) → 422
        //   23xxx = integrity constraint (trùng/FK/not-null) → 409
        // Lỗi thật của server (08 mất kết nối, 42 sai cú pháp = bug ta) → giữ 500 + log.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }
            // PDO errorInfo[0] = SQLSTATE (vd '22P02'); fallback getCode().
            $c = substr((string) ($e->errorInfo[0] ?? $e->getCode() ?? ''), 0, 2);
            if ($c === '22') {
                return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => null], 422);
            }
            if ($c === '23') {
                return response()->json(['status' => 409, 'message' => 'Dữ liệu vi phạm ràng buộc (trùng lặp hoặc liên kết không hợp lệ)', 'data' => null], 409);
            }

            return null; // lỗi server thật → để Laravel log + trả 500
        });
    })->create();
