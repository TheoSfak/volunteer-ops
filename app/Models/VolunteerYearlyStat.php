<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VolunteerYearlyStat extends Model
{
    use HasFactory;

    protected $table = 'volunteer_yearly_stats';

    protected $fillable = [
        'user_id',
        'year',
        'total_shifts',
        'completed_shifts',
        'no_show_count',
        'total_hours',
        'total_points',
        'achievements_earned',
        'final_ranking',
        'total_volunteers_that_year',
        'weekend_shifts',
        'night_shifts',
        'medical_missions',
        'best_streak',
        'favorite_department',
    ];

    protected $casts = [
        'year' => 'integer',
        'total_shifts' => 'integer',
        'completed_shifts' => 'integer',
        'no_show_count' => 'integer',
        'total_hours' => 'decimal:2',
        'total_points' => 'integer',
        'achievements_earned' => 'integer',
        'final_ranking' => 'integer',
        'total_volunteers_that_year' => 'integer',
        'weekend_shifts' => 'integer',
        'night_shifts' => 'integer',
        'medical_missions' => 'integer',
        'best_streak' => 'integer',
    ];

    /**
     * Σχέση με χρήστη.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope για συγκεκριμένο έτος.
     */
    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    /**
     * Scope για συγκεκριμένο χρήστη.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Λήψη ή δημιουργία record για έτος.
     */
    public static function getOrCreateForYear(int $userId, int $year): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId, 'year' => $year],
            [
                'total_shifts' => 0,
                'completed_shifts' => 0,
                'no_show_count' => 0,
                'total_hours' => 0,
                'total_points' => 0,
                'achievements_earned' => 0,
                'weekend_shifts' => 0,
                'night_shifts' => 0,
                'medical_missions' => 0,
                'best_streak' => 0,
            ]
        );
    }
}
