<?php

namespace App\Modules\Missions\Models;

use App\Models\User;
use App\Modules\Directory\Models\Department;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Documents\Models\Document;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mission extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Πίνακας βάσης δεδομένων.
     */
    protected $table = 'missions';

    /**
     * Πεδία που επιτρέπεται η μαζική ανάθεση.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'department_id',
        'title',
        'description',
        'type',
        'location',
        'location_details',
        'latitude',
        'longitude',
        'start_datetime',
        'end_datetime',
        'requirements',
        'notes',
        'is_urgent',
        'coverage_percentage',
        'status',
        'created_by',
    ];

    /**
     * Μετατροπές τύπων.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_urgent' => 'boolean',
        'coverage_percentage' => 'integer',
    ];

    /**
     * Τύποι αποστολών.
     */
    const TYPE_VOLUNTEER = 'VOLUNTEER';
    const TYPE_MEDICAL = 'MEDICAL';

    /**
     * Λίστα τύπων.
     */
    const TYPES = [
        self::TYPE_VOLUNTEER,
        self::TYPE_MEDICAL,
    ];

    /**
     * Ελληνικές ονομασίες τύπων.
     */
    const TYPE_LABELS = [
        self::TYPE_VOLUNTEER => 'Εθελοντική',
        self::TYPE_MEDICAL => 'Ιατρική',
    ];

    /**
     * Καταστάσεις αποστολών.
     */
    const STATUS_DRAFT = 'DRAFT';
    const STATUS_OPEN = 'OPEN';
    const STATUS_CLOSED = 'CLOSED';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_CANCELED = 'CANCELED';

    /**
     * Λίστα καταστάσεων.
     */
    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELED,
    ];

    /**
     * Ελληνικές ονομασίες καταστάσεων.
     */
    const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Πρόχειρο',
        self::STATUS_OPEN => 'Ανοιχτή',
        self::STATUS_CLOSED => 'Κλειστή',
        self::STATUS_COMPLETED => 'Ολοκληρωμένη',
        self::STATUS_CANCELED => 'Ακυρωμένη',
    ];

    /**
     * Σχέση με τμήμα.
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Σχέση με δημιουργό.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Σχέση με βάρδιες.
     */
    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    /**
     * Σχέση με έγγραφα.
     */
    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Ελληνική ονομασία τύπου.
     */
    public function getTypeLabelAttribute(): string
    {
        if (empty($this->type)) {
            return 'Άγνωστος';
        }
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /**
     * Ελληνική ονομασία κατάστασης.
     */
    public function getStatusLabelAttribute(): string
    {
        if (empty($this->status)) {
            return 'Άγνωστη';
        }
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Έλεγχος αν η αποστολή δέχεται συμμετοχές.
     */
    public function acceptsParticipations(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Υπολογισμός ποσοστού κάλυψης.
     */
    public function getCoveragePercentage(): float
    {
        $totalCapacity = $this->shifts()->sum('capacity');
        if ($totalCapacity === 0) {
            return 0;
        }

        $approvedCount = $this->shifts()
            ->withCount(['participations' => function ($q) {
                $q->where('status', 'APPROVED');
            }])
            ->get()
            ->sum('participations_count');

        return round(($approvedCount / $totalCapacity) * 100, 2);
    }

    /**
     * Scope για ανοιχτές αποστολές.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope για αποστολές τμήματος.
     */
    public function scopeForDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }
}
