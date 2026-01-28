<?php

namespace App\Modules\Participation\Models;

use App\Models\User;
use App\Modules\Shifts\Models\Shift;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParticipationRequest extends Model
{
    use HasFactory;

    /**
     * Πίνακας βάσης δεδομένων.
     */
    protected $table = 'participation_requests';

    /**
     * Πεδία που επιτρέπεται η μαζική ανάθεση.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shift_id',
        'volunteer_id',
        'status',
        'notes',
        'rejection_reason',
        'decided_by',
        'decided_at',
        'points_awarded',
    ];

    /**
     * Μετατροπές τύπων.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'decided_at' => 'datetime',
        'points_awarded' => 'boolean',
    ];

    /**
     * Καταστάσεις αιτήσεων.
     */
    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_CANCELED_BY_USER = 'CANCELED_BY_USER';
    const STATUS_CANCELED_BY_ADMIN = 'CANCELED_BY_ADMIN';

    /**
     * Λίστα καταστάσεων.
     */
    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELED_BY_USER,
        self::STATUS_CANCELED_BY_ADMIN,
    ];

    /**
     * Ελληνικές ονομασίες καταστάσεων.
     */
    const STATUS_LABELS = [
        self::STATUS_PENDING => 'Εκκρεμεί',
        self::STATUS_APPROVED => 'Εγκεκριμένη',
        self::STATUS_REJECTED => 'Απορρίφθηκε',
        self::STATUS_CANCELED_BY_USER => 'Ακυρώθηκε από χρήστη',
        self::STATUS_CANCELED_BY_ADMIN => 'Ακυρώθηκε από διαχειριστή',
    ];

    /**
     * Σχέση με βάρδια.
     */
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Σχέση με χρήστη (αιτών/εθελοντής).
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'volunteer_id');
    }

    /**
     * Alias για τη σχέση με εθελοντή.
     */
    public function volunteer()
    {
        return $this->belongsTo(User::class, 'volunteer_id');
    }

    /**
     * Σχέση με χρήστη που πήρε την απόφαση.
     */
    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Ελληνική ονομασία κατάστασης.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Έλεγχος αν η αίτηση είναι εκκρεμής.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Έλεγχος αν η αίτηση είναι εγκεκριμένη.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Scope για εκκρεμείς αιτήσεις.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope για εγκεκριμένες αιτήσεις.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope για αιτήσεις χρήστη.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('volunteer_id', $userId);
    }
}
