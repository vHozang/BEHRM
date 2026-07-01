<?php

namespace App\Providers;

use App\Repositories\AttendanceRepository;
use App\Repositories\Contracts\AttendanceRepositoryContract;
use Illuminate\Support\ServiceProvider;

/**
 * Service Provider: Bind interfaces đến concrete implementations.
 *
 * Đây là "Composition Root" của ứng dụng - nơi duy nhất cấu hình
 * dependency injection bindings. Khi cần thay đổi implementation
 * (e.g., switch sang ElasticSearch cho attendance logs), chỉ cần
 * thay đổi binding ở đây, không đụng đến business logic.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Các interface → implementation mappings.
     * Laravel Container sẽ tự động inject implementation khi
     * type-hint interface trong constructor.
     */
    public array $bindings = [
        AttendanceRepositoryContract::class => AttendanceRepository::class,
    ];

    public function register(): void
    {
        // Bind thủ công nếu cần custom logic (e.g., singleton, with config)
        // $this->app->singleton(AttendanceRepositoryContract::class, function ($app) {
        //     return new AttendanceRepository();
        // });
    }

    public function boot(): void
    {
        //
    }
}
