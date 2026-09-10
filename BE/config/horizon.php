<?php

/**
 * Laravel Horizon Configuration
 * File: config/horizon.php
 *
 * Horizon là dashboard + supervisor cho Laravel Queue (Redis).
 * Nó quản lý, monitor và auto-scale số lượng worker processes.
 *
 * Cài đặt: composer require laravel/horizon
 * Publish config: php artisan horizon:publish
 * Chạy: php artisan horizon
 * Dashboard: http://your-app.com/horizon
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */
    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */
    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    | Horizon dùng Redis để lưu job metrics, queue information.
    | Nên dùng database riêng (DB 2) để tách biệt khỏi app cache (DB 1).
    */
    'use' => 'horizon',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => env(
        'HORIZON_PREFIX',
        'hrm_horizon:'.env('APP_ENV', 'production').':'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    | Bảo vệ Horizon dashboard, chỉ admin mới được xem.
    */
    'middleware' => ['web', 'auth.horizon'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    | Nếu job chờ quá lâu → Horizon sẽ cảnh báo (và có thể trigger auto-scale).
    | attendance queue: cảnh báo nếu chờ > 3 giây (critical path!)
    */
    'waits' => [
        'redis:attendance' => 3,    // 3 giây = emergency threshold cho check-in
        'redis:default' => 60,   // 60 giây cho queue mặc định
        'redis:emails' => 120,  // 2 phút cho email notifications
        'redis:reports' => 300,  // 5 phút cho báo cáo lớn
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming
    |--------------------------------------------------------------------------
    | Thời gian giữ lịch sử job trong Redis (phút).
    */
    'trim' => [
        'recent' => 60,      // Jobs gần đây: giữ 1 giờ
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,   // Jobs thất bại: giữ 7 ngày để debug
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    | Các Job này sẽ không hiện trên Horizon dashboard để tránh noise.
    */
    'silenced' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    | Lưu thống kê job throughput. Chạy: php artisan horizon:snapshot (via cron)
    */
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,   // Giữ 24 giờ snapshot
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    | Worker tự tắt sau khi xử lý xong job hiện tại (graceful shutdown).
    */
    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */
    'memory_limit' => 256,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration (QUAN TRỌNG NHẤT)
    |--------------------------------------------------------------------------
    |
    | 'environments': Cấu hình worker riêng cho mỗi môi trường.
    |
    | 'supervisor-*': Nhóm worker, mỗi supervisor quản lý một loại queue.
    |
    | Chiến lược scaling cho 5000 nhân viên check-in lúc 8h00:
    |
    |   Queue 'attendance':
    |   - Peak (7:45 - 8:30): tối đa 50 workers xử lý song song
    |   - minProcesses=5: luôn giữ tối thiểu 5 workers sẵn sàng
    |   - balance=auto: Horizon tự tăng/giảm dựa trên queue backlog
    |
    |   Tính toán: 5000 jobs / 50 workers / 30s timeout = 3.3 phút để clear queue
    |   → Trong vòng 5 phút sau 8h00, tất cả check-in sẽ được xử lý.
    |
    */
    'environments' => [
        'production' => [

            // ── Supervisor 1: Attendance Queue (HIGH PRIORITY) ────────────
            // Chuyên xử lý check-in/check-out vào giờ cao điểm
            'supervisor-attendance' => [
                'connection' => 'redis',
                'queue' => ['attendance'],  // Chỉ xử lý queue này
                'balance' => 'auto',           // Auto-scale dựa trên workload
                'autoScalingStrategy' => 'time',   // Scale dựa trên wait time
                'minProcesses' => 5,               // Luôn có ít nhất 5 workers
                'maxProcesses' => 50,              // Tối đa 50 workers khi peak
                'balanceMaxShift' => 10,         // Tăng/giảm tối đa 10 workers mỗi lần scale
                'balanceCooldown' => 3,          // Chờ 3 giây giữa mỗi lần scale
                'memory' => 256,             // Restart worker nếu dùng > 256MB
                'tries' => 3,               // Retry 3 lần
                'timeout' => 30,              // Kill job nếu chạy > 30 giây
                'sleep' => 1,               // Sleep 1 giây khi queue rỗng
                'nice' => -5,              // Process priority (lower = higher priority)
            ],

            // ── Supervisor 2: Default Queue (NORMAL PRIORITY) ─────────────
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'simple',
                'minProcesses' => 2,
                'maxProcesses' => 10,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 60,
                'sleep' => 3,
            ],

            // ── Supervisor 3: Email/Notification Queue (LOW PRIORITY) ──────
            'supervisor-notifications' => [
                'connection' => 'redis',
                'queue' => ['emails', 'notifications'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'memory' => 128,
                'tries' => 5,
                'timeout' => 120,
                'sleep' => 5,
                'nice' => 10,  // Low priority
            ],

            // ── Supervisor 4: Heavy Jobs Queue (BACKGROUND) ───────────────
            // Payroll calculation, reports, AI scoring
            'supervisor-heavy' => [
                'connection' => 'redis',
                'queue' => ['reports', 'payroll', 'ai-scoring'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'memory' => 512,
                'tries' => 2,
                'timeout' => 600,  // 10 phút cho job phức tạp
                'sleep' => 10,
                'nice' => 19,   // Lowest priority
            ],
        ],

        'local' => [
            'supervisor-local' => [
                'connection' => 'redis',
                'queue' => ['attendance', 'default', 'emails'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'memory' => 256,
                'tries' => 1,
                'timeout' => 60,
                'sleep' => 3,
            ],
        ],
    ],
];
