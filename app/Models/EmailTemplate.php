<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    /**
     * Κωδικοί templates.
     */
    const CODE_PARTICIPATION_APPROVED = 'participation_approved';
    const CODE_PARTICIPATION_REJECTED = 'participation_rejected';
    const CODE_SHIFT_REMINDER = 'shift_reminder';
    const CODE_WELCOME = 'welcome';
    const CODE_PASSWORD_RESET = 'password_reset';
    const CODE_NEW_MISSION = 'new_mission';

    protected $fillable = [
        'code',
        'name',
        'description',
        'subject',
        'body',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Λίστα διαθέσιμων placeholders ανά template.
     */
    const PLACEHOLDERS = [
        self::CODE_PARTICIPATION_APPROVED => [
            '{{volunteer_name}}' => 'Όνομα εθελοντή',
            '{{mission_title}}' => 'Τίτλος αποστολής',
            '{{shift_date}}' => 'Ημερομηνία βάρδιας',
            '{{shift_time}}' => 'Ώρα βάρδιας',
            '{{location}}' => 'Τοποθεσία',
            '{{app_name}}' => 'Όνομα εφαρμογής',
        ],
        self::CODE_PARTICIPATION_REJECTED => [
            '{{volunteer_name}}' => 'Όνομα εθελοντή',
            '{{mission_title}}' => 'Τίτλος αποστολής',
            '{{shift_date}}' => 'Ημερομηνία βάρδιας',
            '{{rejection_reason}}' => 'Λόγος απόρριψης',
            '{{app_name}}' => 'Όνομα εφαρμογής',
        ],
        self::CODE_SHIFT_REMINDER => [
            '{{volunteer_name}}' => 'Όνομα εθελοντή',
            '{{mission_title}}' => 'Τίτλος αποστολής',
            '{{shift_date}}' => 'Ημερομηνία βάρδιας',
            '{{shift_time}}' => 'Ώρα βάρδιας',
            '{{location}}' => 'Τοποθεσία',
            '{{hours_until}}' => 'Ώρες μέχρι τη βάρδια',
            '{{app_name}}' => 'Όνομα εφαρμογής',
        ],
        self::CODE_WELCOME => [
            '{{volunteer_name}}' => 'Όνομα εθελοντή',
            '{{email}}' => 'Email χρήστη',
            '{{login_url}}' => 'URL σύνδεσης',
            '{{app_name}}' => 'Όνομα εφαρμογής',
        ],
        self::CODE_PASSWORD_RESET => [
            '{{volunteer_name}}' => 'Όνομα εθελοντή',
            '{{reset_url}}' => 'URL επαναφοράς κωδικού',
            '{{expiry_minutes}}' => 'Λεπτά ισχύος συνδέσμου',
            '{{app_name}}' => 'Όνομα εφαρμογής',
        ],
        self::CODE_NEW_MISSION => [
            '{{volunteer_name}}' => 'Όνομα εθελοντή',
            '{{mission_title}}' => 'Τίτλος αποστολής',
            '{{mission_description}}' => 'Περιγραφή αποστολής',
            '{{start_date}}' => 'Ημερομηνία έναρξης',
            '{{location}}' => 'Τοποθεσία',
            '{{mission_url}}' => 'URL αποστολής',
            '{{app_name}}' => 'Όνομα εφαρμογής',
        ],
    ];

    /**
     * Λήψη template με κωδικό.
     */
    public static function getByCode(string $code): ?self
    {
        return static::where('code', $code)->where('is_active', true)->first();
    }

    /**
     * Render template με αντικατάσταση placeholders.
     */
    public function render(array $data): array
    {
        $subject = $this->subject;
        $body = $this->body;

        foreach ($data as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $subject = str_replace($placeholder, $value, $subject);
            $body = str_replace($placeholder, $value, $body);
        }

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * Λήψη διαθέσιμων placeholders για αυτό το template.
     */
    public function getPlaceholders(): array
    {
        return self::PLACEHOLDERS[$this->code] ?? [];
    }
}
