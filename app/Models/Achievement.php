<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Achievement extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'icon',
        'color',
        'category',
        'threshold',
        'points_reward',
        'is_active',
    ];

    protected $casts = [
        'threshold' => 'integer',
        'points_reward' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Κατηγορίες επιτευγμάτων.
     */
    const CATEGORY_HOURS = 'hours';
    const CATEGORY_SHIFTS = 'shifts';
    const CATEGORY_SPECIAL = 'special';
    const CATEGORY_STREAK = 'streak';
    const CATEGORY_MILESTONE = 'milestone';

    const CATEGORY_LABELS = [
        self::CATEGORY_HOURS => 'Ώρες Εθελοντισμού',
        self::CATEGORY_SHIFTS => 'Βάρδιες',
        self::CATEGORY_SPECIAL => 'Ειδικά',
        self::CATEGORY_STREAK => 'Συνέπεια',
        self::CATEGORY_MILESTONE => 'Ορόσημα',
    ];

    /**
     * Κωδικοί επιτευγμάτων.
     */
    const CODE_FIRST_SHIFT = 'first_shift';
    const CODE_HOURS_50 = 'hours_50';
    const CODE_HOURS_100 = 'hours_100';
    const CODE_HOURS_250 = 'hours_250';
    const CODE_HOURS_500 = 'hours_500';
    const CODE_HOURS_1000 = 'hours_1000';
    const CODE_SHIFTS_10 = 'shifts_10';
    const CODE_SHIFTS_25 = 'shifts_25';
    const CODE_SHIFTS_50 = 'shifts_50';
    const CODE_SHIFTS_100 = 'shifts_100';
    const CODE_RELIABLE_10 = 'reliable_10';
    const CODE_RELIABLE_25 = 'reliable_25';
    const CODE_RELIABLE_50 = 'reliable_50';
    const CODE_WEEKEND_WARRIOR = 'weekend_warrior';
    const CODE_NIGHT_OWL = 'night_owl';
    const CODE_MEDICAL_HERO = 'medical_hero';
    const CODE_EARLY_ADOPTER = 'early_adopter';
    const CODE_TEAM_PLAYER = 'team_player';

    public function volunteers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'volunteer_achievements')
            ->withPivot('earned_at', 'notified')
            ->withTimestamps();
    }

    /**
     * Label κατηγορίας.
     */
    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }

    /**
     * Scope για ενεργά επιτεύγματα.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope ανά κατηγορία.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Επιστρέφει το εικονίδιο Font Awesome.
     */
    public function getIconClassAttribute(): string
    {
        return $this->icon ?: 'fas fa-trophy';
    }

    /**
     * Επιστρέφει το χρώμα badge.
     */
    public function getBadgeColorAttribute(): string
    {
        return $this->color ?: 'primary';
    }
}
