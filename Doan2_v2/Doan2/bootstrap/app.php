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
    })->create();
