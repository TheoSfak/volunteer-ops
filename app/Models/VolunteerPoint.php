<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerPoint extends Model
{
    protected $fillable = [
        'user_id',
        'points',
        'reason',
        'description',
        'pointable_type',
        'pointable_id',
    ];

    /**
     * Λόγοι πόντων.
     */
    const REASON_SHIFT_COMPLETED = 'shift_completed';
    const REASON_WEEKEND_BONUS = 'weekend_bonus';
    const REASON_NIGHT_BONUS = 'night_bonus';
    const REASON_MEDICAL_BONUS = 'medical_bonus';
    const REASON_LAST_MINUTE = 'last_minute';
    const REASON_ACHIEVEMENT = 'achievement';
    const REASON_STREAK_BONUS = 'streak_bonus';
    const REASON_MANUAL = 'manual';

    const REASON_LABELS = [
        self::REASON_SHIFT_COMPLETED => 'Ολοκλήρωση Βάρδιας',
        self::REASON_WEEKEND_BONUS => 'Μπόνους Σαββατοκύριακου',
        self::REASON_NIGHT_BONUS => 'Μπόνους Νυχτερινής',
        self::REASON_MEDICAL_BONUS => 'Μπόνους Υγειονομικής',
        self::REASON_LAST_MINUTE => 'Έκτακτη Κάλυψη',
        self::REASON_ACHIEVEMENT => 'Επίτευγμα',
        self::REASON_STREAK_BONUS => 'Μπόνους Συνέπειας',
        self::REASON_MANUAL => 'Χειροκίνητη Απονομή',
    ];

    /**
     * Πόντοι ανά ώρα εθελοντισμού.
     */
    const POINTS_PER_HOUR = 10;
    const WEEKEND_MULTIPLIER = 1.5;
    const NIGHT_MULTIPLIER = 1.5;
    const MEDICAL_MULTIPLIER = 2.0;
    const LAST_MINUTE_BONUS = 20;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pointable()
    {
        return $this->morphTo();
    }

    /**
     * Label λόγου.
     */
    public function getReasonLabelAttribute(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }
}
