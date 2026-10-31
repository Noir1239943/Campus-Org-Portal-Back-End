<?php

namespace App\Support;

/** Public school/term settings shared with the frontend. */
class Campus
{
    public static function meta(): array
    {
        return [
            'school_name' => config('campus.school_name'),
            'school_short' => config('campus.school_short'),
            'location' => config('campus.location'),
            'osa_email' => config('campus.osa_email'),
            'academic_year' => config('campus.academic_year'),
            'semester' => config('campus.semester'),
            'service_hours_goal' => config('campus.service_hours_goal'),
            'attendance_threshold' => config('campus.attendance_threshold'),
            'late_after_minutes' => config('campus.late_after_minutes'),
            'excuse_window_days' => config('campus.excuse_window_days'),
            'require_event_approval' => config('campus.require_event_approval'),
            'check_in_code_seconds' => config('campus.check_in_code_seconds'),
        ];
    }
}
