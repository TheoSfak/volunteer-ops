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
        // Νέα πεδία για παρουσία
        'attended',
        'actual_hours',
        'actual_start_time',
        'actual_end_time',
        'admin_notes',
        'attendance_confirmed_at',
        'attendance_confirmed_by',
    ];

    /**
     * Μετατροπές τύπων.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'decided_at' => 'datetime',
        'points_awarded' => 'boolean',
        'attended' => 'boolean',
        'actual_hours' => 'decimal:2',
        'attendance_confirmed_at' => 'datetime',
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

    /**
     * Σχέση με τον χρήστη που επιβεβαίωσε την παρουσία.
     */
    public function attendanceConfirmer()
    {
        return $this->belongsTo(User::class, 'attendance_confirmed_by');
    }

    /**
     * Έλεγχος αν ήρθε ο εθελοντής.
     */
    public function didAttend(): bool
    {
        return $this->attended === true;
    }

    /**
     * Έλεγχος αν είναι no-show.
     */
    public function isNoShow(): bool
    {
        return $this->attended === false;
    }

    /**
     * Υπολογισμός ωρών εργασίας.
     * Αν υπάρχουν actual_hours, επιστρέφει αυτές.
     * Αλλιώς υπολογίζει από actual_start/end_time ή από τη βάρδια.
     */
    public function getCalculatedHoursAttribute(): float
    {
        // Αν έχει οριστεί χειροκίνητα
        if ($this->actual_hours !== null) {
            return (float) $this->actual_hours;
        }
        
        // Αν έχει πραγματικές ώρες έναρξης/λήξης
        if ($this->actual_start_time && $this->actual_end_time) {
            $start = \Carbon\Carbon::parse($this->actual_start_time);
            $end = \Carbon\Carbon::parse($this->actual_end_time);
            return abs($end->diffInMinutes($start)) / 60;
        }
        
        // Fallback στις ώρες της βάρδιας
        if ($this->shift) {
            $start = \Carbon\Carbon::parse($this->shift->start_time);
            $end = \Carbon\Carbon::parse($this->shift->end_time);
            return abs($end->diffInMinutes($start)) / 60;
        }
        
        return 0;
    }

    /**
     * Scope για συμμετοχές που ήρθαν.
     */
    public function scopeAttended($query)
    {
        return $query->where('attended', true);
    }

    /**
     * Scope για no-shows.
     */
    public function scopeNoShow($query)
    {
        return $query->where('attended', false);
    }
}
