<?php

return [
    // Pakistan-specific defaults, overridable per organization/campus via settings table.
    'default_organization_id' => env('CMS_DEFAULT_ORG_ID', 1),

    'country' => 'PK',
    'currency' => 'PKR',
    'timezone' => 'Asia/Karachi',
    'frontend_url' => env('CMS_FRONTEND_URL', 'http://localhost:5173'),
    'date_format' => 'd/m/Y',
    'academic_year_start_month' => 4,   // April, BISE convention
    'academic_year_end_month' => 3,
    'week_starts_on' => 'monday',
    'boards' => ['BISE-KHI', 'BISE-LHR', 'BISE-ISB', 'BISE-PESH', 'FBISE', 'A-Level', 'O-Level'],
    // What a campus is. Drives which campus-life modules apply: an early-years campus has no
    // boarding house, and certificates start being issued once students are in junior school.
    'school_types' => ['montessori', 'junior', 'secondary', 'higher_secondary', 'college', 'university'],
    'cnic_pattern' => '/^\d{5}-\d{7}-[\dX]$/',
    'phone_pattern' => '/^(\+92|0)[ -]?\d{9,12}$/',

    'password_min' => 8,
    'login_throttle' => ['max_attempts' => 5, 'decay_minutes' => 15],
    'session_idle_minutes' => 120,

    // Outbound SMS. Credentials are environment-only: the settings table is returned in
    // full by /settings, so an API key stored there would leak to any settings.view holder.
    // The campus-level setting only picks the driver (log|gateway).
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'sender_id' => env('SMS_SENDER_ID', 'SUNRISE'),
        'gateway_url' => env('SMS_GATEWAY_URL'),
        'gateway_api_key' => env('SMS_GATEWAY_API_KEY'),
        'timeout_seconds' => (int) env('SMS_TIMEOUT', 5),
    ],
];
