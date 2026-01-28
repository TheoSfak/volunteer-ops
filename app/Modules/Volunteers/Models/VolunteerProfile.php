<?php

namespace App\Modules\Volunteers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VolunteerProfile extends Model
{
    use HasFactory;

    /**
     * Πίνακας βάσης δεδομένων.
     */
    protected $table = 'volunteer_profiles';

    /**
     * Πρωτεύον κλειδί.
     */
    protected $primaryKey = 'user_id';

    /**
     * Απενεργοποίηση auto-increment.
     */
    public $incrementing = false;

    /**
     * Πεδία που επιτρέπεται η μαζική ανάθεση.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'rank',
        'specialties',
        'certifications',
        'emergency_contact',
        'date_of_birth',
        'blood_type',
        'medical_notes',
        'availability',
        'address',
        'city',
        'postal_code',
    ];

    /**
     * Μετατροπές τύπων.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_of_birth' => 'date',
        'specialties' => 'array',
        'certifications' => 'array',
        'emergency_contact' => 'array',
        'availability' => 'array',
    ];

    /**
     * Διαθέσιμοι βαθμοί εθελοντών.
     */
    const RANK_DOKIMOS = 'DOKIMOS';      // Δόκιμος
    const RANK_ENERGOS = 'ENERGOS';      // Ενεργός

    /**
     * Λίστα βαθμών.
     */
    const RANKS = [
        self::RANK_DOKIMOS,
        self::RANK_ENERGOS,
    ];

    /**
     * Ελληνικές ονομασίες βαθμών.
     */
    const RANK_LABELS = [
        self::RANK_DOKIMOS => 'Δόκιμος',
        self::RANK_ENERGOS => 'Ενεργός',
    ];

    /**
     * Σχέση με χρήστη.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ελληνική ονομασία βαθμού.
     */
    public function getRankLabelAttribute(): string
    {
        return self::RANK_LABELS[$this->rank] ?? $this->rank;
    }

    /**
     * Έλεγχος αν ο εθελοντής είναι ενεργός.
     */
    public function isActive(): bool
    {
        return $this->rank === self::RANK_ENERGOS;
    }

    /**
     * Scope για ενεργούς εθελοντές.
     */
    public function scopeActive($query)
    {
        return $query->where('rank', self::RANK_ENERGOS);
    }

    /**
     * Scope για δόκιμους εθελοντές.
     */
    public function scopeProbationary($query)
    {
        return $query->where('rank', self::RANK_DOKIMOS);
    }
}
