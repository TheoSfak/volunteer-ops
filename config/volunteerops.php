<?php

/**
 * VolunteerOps Application Configuration
 * 
 * Αυτό το αρχείο περιέχει όλες τις προεπιλεγμένες τιμές για το σύστημα.
 * Μπορούν να παρακαμφθούν μέσω της βάσης δεδομένων (settings table).
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Shifts Configuration
    |--------------------------------------------------------------------------
    */
    'shifts' => [
        'default_capacity' => env('DEFAULT_SHIFT_CAPACITY', 20),
        'default_duration_hours' => env('DEFAULT_SHIFT_DURATION', 4),
        'max_per_volunteer' => env('MAX_SHIFTS_PER_VOLUNTEER', 5),
        'reminder_hours_before' => env('SHIFT_REMINDER_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gamification Configuration
    |--------------------------------------------------------------------------
    */
    'gamification' => [
        'points_per_hour' => env('POINTS_PER_HOUR', 10),
        'weekend_multiplier' => env('WEEKEND_MULTIPLIER', 1.5),
        'night_multiplier' => env('NIGHT_MULTIPLIER', 1.3),
        'medical_multiplier' => env('MEDICAL_MULTIPLIER', 1.2),
        'night_start_hour' => env('NIGHT_START_HOUR', 22),
        'night_end_hour' => env('NIGHT_END_HOUR', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination Configuration
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'missions_per_page' => env('MISSIONS_PER_PAGE', 15),
        'volunteers_per_page' => env('VOLUNTEERS_PER_PAGE', 20),
        'shifts_per_page' => env('SHIFTS_PER_PAGE', 15),
        'participations_per_page' => env('PARTICIPATIONS_PER_PAGE', 20),
        'audit_logs_per_page' => env('AUDIT_LOGS_PER_PAGE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Configuration
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'recent_missions_count' => env('RECENT_MISSIONS_COUNT', 5),
        'upcoming_shifts_count' => env('UPCOMING_SHIFTS_COUNT', 5),
        'top_volunteers_count' => env('TOP_VOLUNTEERS_COUNT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mission Types (defaults)
    |--------------------------------------------------------------------------
    */
    'mission_types' => [
        'VOLUNTEER' => 'Εθελοντική',
        'MEDICAL' => 'Ιατρική',
        'HUMANITARIAN' => 'Ανθρωπιστική',
        'EDUCATIONAL' => 'Εκπαιδευτική',
        'ENVIRONMENTAL' => 'Περιβαλλοντική',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Configuration
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        'max_file_size_kb' => env('MAX_FILE_SIZE_KB', 5120), // 5MB
        'allowed_document_types' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'],
        'allowed_image_types' => ['jpg', 'jpeg', 'png', 'gif'],
        'email_logo_max_kb' => 512,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Durations (in minutes)
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'settings' => env('CACHE_SETTINGS_MINUTES', 60),
        'statistics' => env('CACHE_STATISTICS_MINUTES', 5),
        'leaderboard' => env('CACHE_LEADERBOARD_MINUTES', 10),
    ],
];
