<?php

namespace App\Modules\Documents\Models;

use App\Modules\Directory\Models\Department;
use App\Modules\Missions\Models\Mission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Πίνακας βάσης δεδομένων.
     */
    protected $table = 'documents';

    /**
     * Πεδία που επιτρέπεται η μαζική ανάθεση.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'category',
        'visibility',
        'file_id',
        'mission_id',
        'department_id',
        'created_by',
    ];

    /**
     * Κατηγορίες εγγράφων.
     */
    const CATEGORY_GENERAL = 'GENERAL';
    const CATEGORY_MISSION = 'MISSION';
    const CATEGORY_CERT = 'CERT';

    /**
     * Λίστα κατηγοριών.
     */
    const CATEGORIES = [
        self::CATEGORY_GENERAL,
        self::CATEGORY_MISSION,
        self::CATEGORY_CERT,
    ];

    /**
     * Ελληνικές ονομασίες κατηγοριών.
     */
    const CATEGORY_LABELS = [
        self::CATEGORY_GENERAL => 'Γενικό',
        self::CATEGORY_MISSION => 'Αποστολή',
        self::CATEGORY_CERT => 'Πιστοποιητικό',
    ];

    /**
     * Επίπεδα ορατότητας.
     */
    const VISIBILITY_PUBLIC = 'PUBLIC';
    const VISIBILITY_ADMINS = 'ADMINS';
    const VISIBILITY_PRIVATE = 'PRIVATE';

    /**
     * Λίστα ορατότητας.
     */
    const VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_ADMINS,
        self::VISIBILITY_PRIVATE,
    ];

    /**
     * Ελληνικές ονομασίες ορατότητας.
     */
    const VISIBILITY_LABELS = [
        self::VISIBILITY_PUBLIC => 'Δημόσιο',
        self::VISIBILITY_ADMINS => 'Διαχειριστές',
        self::VISIBILITY_PRIVATE => 'Ιδιωτικό',
    ];

    /**
     * Σχέση με αρχείο.
     */
    public function file()
    {
        return $this->belongsTo(File::class);
    }

    /**
     * Σχέση με τμήμα.
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Σχέση με αποστολή.
     */
    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * Ελληνική ονομασία κατηγορίας.
     */
    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }

    /**
     * Ελληνική ονομασία ορατότητας.
     */
    public function getVisibilityLabelAttribute(): string
    {
        return self::VISIBILITY_LABELS[$this->visibility] ?? $this->visibility;
    }

    /**
     * Scope για δημόσια έγγραφα.
     */
    public function scopePublic($query)
    {
        return $query->where('visibility', self::VISIBILITY_PUBLIC);
    }

    /**
     * Scope για έγγραφα αποστολής.
     */
    public function scopeForMission($query, $missionId)
    {
        return $query->where('mission_id', $missionId);
    }
}
