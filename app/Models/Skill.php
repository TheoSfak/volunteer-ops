<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    /**
     * Κατηγορίες δεξιοτήτων.
     */
    const CATEGORY_LICENSE = 'license';
    const CATEGORY_LANGUAGE = 'language';
    const CATEGORY_CERTIFICATION = 'certification';
    const CATEGORY_OTHER = 'other';

    const CATEGORIES = [
        self::CATEGORY_LICENSE => 'Διπλώματα',
        self::CATEGORY_LANGUAGE => 'Γλώσσες',
        self::CATEGORY_CERTIFICATION => 'Πιστοποιήσεις',
        self::CATEGORY_OTHER => 'Άλλα',
    ];

    protected $fillable = [
        'name',
        'category',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Σχέση με χρήστες που έχουν αυτή τη δεξιότητα.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_skills')
            ->withPivot('details', 'issued_at', 'expires_at')
            ->withTimestamps();
    }

    /**
     * Ελληνική ονομασία κατηγορίας.
     */
    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /**
     * Scope: Μόνο ενεργές δεξιότητες.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Ανά κατηγορία.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
