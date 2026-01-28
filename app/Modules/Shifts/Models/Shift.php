<?php

namespace App\Modules\Shifts\Models;

use App\Models\User;
use App\Modules\Missions\Models\Mission;
use App\Modules\Participation\Models\ParticipationRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Πίνακας βάσης δεδομένων.
     */
    protected $table = 'shifts';

    /**
     * Πεδία που επιτρέπεται η μαζική ανάθεση.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'mission_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'max_capacity',
        'current_count',
        'status',
        'leader_id',
        'location',
        'notes',
        'required_skills',
    ];

    /**
     * Μετατροπές τύπων.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'max_capacity' => 'integer',
        'current_count' => 'integer',
    ];

    /**
     * Καταστάσεις βαρδιών.
     */
    const STATUS_OPEN = 'OPEN';
    const STATUS_FULL = 'FULL';
    const STATUS_LOCKED = 'LOCKED';
    const STATUS_CANCELED = 'CANCELED';

    /**
     * Λίστα καταστάσεων.
     */
    const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_FULL,
        self::STATUS_LOCKED,
        self::STATUS_CANCELED,
    ];

    /**
     * Ελληνικές ονομασίες καταστάσεων.
     */
    const STATUS_LABELS = [
        self::STATUS_OPEN => 'Ανοιχτή',
        self::STATUS_FULL => 'Πλήρης',
        self::STATUS_LOCKED => 'Κλειδωμένη',
        self::STATUS_CANCELED => 'Ακυρωμένη',
    ];

    /**
     * Σχέση με αποστολή.
     */
    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * Σχέση με αρχηγό βάρδιας.
     */
    public function leader()
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    /**
     * Σχέση με αιτήματα συμμετοχής.
     */
    public function participations()
    {
        return $this->hasMany(ParticipationRequest::class);
    }

    /**
     * Εγκεκριμένες συμμετοχές.
     */
    public function approvedParticipations()
    {
        return $this->participations()->where('status', ParticipationRequest::STATUS_APPROVED);
    }

    /**
     * Ελληνική ονομασία κατάστασης.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Διαθέσιμες θέσεις.
     */
    public function getAvailableSlotsAttribute(): int
    {
        return max(0, $this->max_capacity - $this->current_count);
    }

    /**
     * Έλεγχος αν η βάρδια δέχεται αιτήσεις.
     */
    public function acceptsRequests(): bool
    {
        return $this->status === self::STATUS_OPEN && 
               $this->available_slots > 0 &&
               $this->mission->acceptsParticipations();
    }

    /**
     * Ενημέρωση κατάστασης βάρδιας βάσει χωρητικότητας.
     */
    public function updateStatusBasedOnCapacity(): void
    {
        if ($this->status === self::STATUS_CANCELED || $this->status === self::STATUS_LOCKED) {
            return;
        }

        if ($this->available_slots === 0) {
            $this->update(['status' => self::STATUS_FULL]);
        } elseif ($this->status === self::STATUS_FULL && $this->available_slots > 0) {
            $this->update(['status' => self::STATUS_OPEN]);
        }
    }

    /**
     * Διάρκεια βάρδιας σε ώρες.
     */
    public function getDurationHoursAttribute(): float
    {
        if (!$this->start_time || !$this->end_time) {
            return 0;
        }
        return $this->start_time->diffInHours($this->end_time);
    }

    /**
     * Scope για ανοιχτές βάρδιες.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope για μελλοντικές βάρδιες.
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', now());
    }
}
