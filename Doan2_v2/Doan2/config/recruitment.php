<?php

return [
    'mail' => [
        'from_address' => env('RECRUITMENT_MAIL_FROM_ADDRESS', 'hr@devtapcode.io.vn'),
        'from_name' => env('RECRUITMENT_MAIL_FROM_NAME', 'DEVTAPCODE HR'),
        'company_name' => env('RECRUITMENT_COMPANY_NAME'),
        'company_address' => env('RECRUITMENT_COMPANY_ADDRESS', ''),
        'company_phone' => env('RECRUITMENT_COMPANY_PHONE', ''),
        'website_url' => env('RECRUITMENT_WEBSITE_URL', env('APP_URL', 'https://devtapcode.io.vn')),
        'recruiter_name' => env('RECRUITMENT_CONTACT_NAME', 'Bộ phận Tuyển dụng'),
        'recruiter_title' => env('RECRUITMENT_CONTACT_TITLE', 'HR / Talent Acquisition'),
        'application_response_days' => (int) env('RECRUITMENT_RESPONSE_DAYS', 3),
        'interview_duration_minutes' => (int) env('RECRUITMENT_INTERVIEW_DURATION', 60),
        'default_start_time' => env('RECRUITMENT_DEFAULT_START_TIME', '08:30'),
    ],
];
