<?php

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5000'))
)));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    // Dev/local: cho MỌI cổng localhost/127.0.0.1 (Vite đổi cổng linh hoạt: 5000,
    // 5050…). Production (APP_ENV=production) rỗng → chỉ dùng CORS_ALLOWED_ORIGINS
    // ở trên. CORS chỉ nới cho trang PHỤC VỤ từ localhost (không giúp kẻ tấn công remote).
    'allowed_origins_patterns' => env('APP_ENV') === 'production'
        ? []
        : ['#^http://(localhost|127\.0\.0\.1)(:\d+)?$#'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => true,
];
