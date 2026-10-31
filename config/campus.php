<?php

/*
|--------------------------------------------------------------------------
| Campus settings
|--------------------------------------------------------------------------
| School-wide settings used across the API. Change these through the .env
| file (or Render environment variables) at the start of every term.
*/

return [
    'school_name' => env('SCHOOL_NAME', 'Cordova Public College'),
    'school_short' => env('SCHOOL_SHORT', 'CPC'),
    'location' => env('SCHOOL_LOCATION', 'Cordova, Cebu'),
    'osa_email' => env('OSA_EMAIL', 'osa@cpc.edu.ph'),

    'academic_year' => env('ACADEMIC_YEAR', '2026–2027'),
    'semester' => env('SEMESTER', '1st Semester'),

    // Months shown on the adviser activity chart (1 = January).
    'semester_months' => array_map('intval', explode(',', env('SEMESTER_MONTHS', '8,9,10,11,12'))),

    // Community service hours every student is encouraged to reach per term.
    'service_hours_goal' => (int) env('SERVICE_HOURS_GOAL', 20),

    // Organizations whose average attendance falls below this % are flagged.
    'attendance_threshold' => (int) env('ATTENDANCE_THRESHOLD', 75),

    // A member is "at risk" when their attendance in an organization falls below this %.
    'member_risk_threshold' => (int) env('MEMBER_RISK_THRESHOLD', 70),

    // Check-ins after the start time plus this many minutes are marked "late".
    'late_after_minutes' => (int) env('LATE_AFTER_MINUTES', 15),

    // How often the event check-in code / QR rotates.
    'check_in_code_seconds' => (int) env('CHECK_IN_CODE_SECONDS', 60),

    // Students may file an excuse up to this many days after an event.
    'excuse_window_days' => (int) env('EXCUSE_WINDOW_DAYS', 7),

    // When true, officer events need adviser (OSA) approval before they are published.
    'require_event_approval' => (bool) env('REQUIRE_EVENT_APPROVAL', true),

    // Used for the QR check-in link.
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/'),
];
