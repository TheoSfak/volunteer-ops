<?php

namespace App\Models;

use App\Modules\Volunteers\Models\VolunteerProfile;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Directory\Models\Department;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Skill;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Πεδία που επιτρέπεται η μαζική ανάθεση.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'department_id',
        'role',
        'is_active',
        'total_points',
        'monthly_points',
    ];

    /**
     * Κρυφά πεδία.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Μετατροπές τύπων.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'total_points' => 'integer',
        'monthly_points' => 'integer',
    ];

    /**
     * Διαθέσιμοι ρόλοι στο σύστημα.
     */
    const ROLE_SYSTEM_ADMIN = 'SYSTEM_ADMIN';
    const ROLE_DEPARTMENT_ADMIN = 'DEPARTMENT_ADMIN';
    const ROLE_SHIFT_LEADER = 'SHIFT_LEADER';
    const ROLE_VOLUNTEER = 'VOLUNTEER';

    /**
     * Λίστα όλων των ρόλων.
     */
    const ROLES = [
        self::ROLE_SYSTEM_ADMIN,
        self::ROLE_DEPARTMENT_ADMIN,
        self::ROLE_SHIFT_LEADER,
        self::ROLE_VOLUNTEER,
    ];

    /**
     * Ελληνικές ονομασίες ρόλων.
     */
    const ROLE_LABELS = [
        self::ROLE_SYSTEM_ADMIN => 'Διαχειριστής Συστήματος',
        self::ROLE_DEPARTMENT_ADMIN => 'Διαχειριστής Τμήματος',
        self::ROLE_SHIFT_LEADER => 'Αρχηγός Βάρδιας',
        self::ROLE_VOLUNTEER => 'Εθελοντής',
    ];

    /**
     * Σχέση με τμήμα.
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Σχέση με προφίλ εθελοντή.
     */
    public function volunteerProfile()
    {
        return $this->hasOne(VolunteerProfile::class);
    }

    /**
     * Σχέση με αιτήματα συμμετοχής.
     */
    public function participationRequests()
    {
        return $this->hasMany(ParticipationRequest::class, 'volunteer_id');
    }

    /**
     * Σχέση με επιτεύγματα (achievements).
     */
    public function achievements()
    {
        return $this->belongsToMany(Achievement::class, 'volunteer_achievements')
            ->withPivot('earned_at', 'notified')
            ->withTimestamps();
    }

    /**
     * Σχέση με πόντους.
     */
    public function volunteerPoints()
    {
        return $this->hasMany(VolunteerPoint::class);
    }

    /**
     * Σχέση με δεξιότητες/διπλώματα.
     */
    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'user_skills')
            ->withPivot('details', 'issued_at', 'expires_at')
            ->withTimestamps();
    }

    /**
     * Έλεγχος αν ο χρήστης έχει συγκεκριμένο ρόλο.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Έλεγχος αν ο χρήστης έχει κάποιον από τους ρόλους.
     */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles);
    }

    /**
     * Έλεγχος αν ο χρήστης είναι διαχειριστής.
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole([
            self::ROLE_SYSTEM_ADMIN,
            self::ROLE_DEPARTMENT_ADMIN,
        ]);
    }

    /**
     * Ελληνική ονομασία ρόλου.
     */
    public function getRoleLabelAttribute(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }

    /**
     * Cached hours by type - αποφυγή N+1 queries.
     */
    protected ?array $cachedHoursByType = null;

    /**
     * Υπολογισμός ωρών ανά τύπο αποστολής.
     * Επιστρέφει array με εθελοντικές και υγειονομικές ώρες.
     * Χρησιμοποιεί caching για αποφυγή επαναλαμβανόμενων queries.
     */
    public function getHoursByType(): array
    {
        if ($this->cachedHoursByType !== null) {
            return $this->cachedHoursByType;
        }

        $hours = [
            'volunteer' => 0,  // Εθελοντικές
            'medical' => 0,    // Υγειονομικές
            'total' => 0,      // Σύνολο
        ];
        
        $approvedParticipations = $this->participationRequests()
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift.mission')
            ->get();
        
        foreach ($approvedParticipations as $participation) {
            if ($participation->shift && $participation->shift->start_time && $participation->shift->end_time) {
                $minutes = $participation->shift->end_time->diffInMinutes($participation->shift->start_time);
                $h = round($minutes / 60, 1);
                
                $missionType = $participation->shift->mission->type ?? 'VOLUNTEER';
                
                if ($missionType === 'MEDICAL') {
                    $hours['medical'] += $h;
                } else {
                    $hours['volunteer'] += $h;
                }
                $hours['total'] += $h;
            }
        }
        
        $this->cachedHoursByType = $hours;
        return $hours;
    }

    /**
     * Εθελοντικές ώρες.
     */
    public function getVolunteerHoursAttribute(): float
    {
        return $this->getHoursByType()['volunteer'];
    }

    /**
     * Υγειονομικές ώρες.
     */
    public function getMedicalHoursAttribute(): float
    {
        return $this->getHoursByType()['medical'];
    }

    /**
     * Υπολογισμός συνολικών ωρών εθελοντισμού.
     * Βασίζεται στις εγκεκριμένες συμμετοχές σε βάρδιες.
     */
    public function getTotalVolunteerHoursAttribute(): float
    {
        return $this->getHoursByType()['total'];
    }

    /**
     * Λήψη ιστορικού συμμετοχών με ώρες.
     */
    public function getParticipationHistory()
    {
        return $this->participationRequests()
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with(['shift.mission'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($participation) {
                $hours = 0;
                if ($participation->shift && $participation->shift->start_time && $participation->shift->end_time) {
                    $hours = round($participation->shift->end_time->diffInMinutes($participation->shift->start_time) / 60, 1);
                }
                
                $mission = $participation->shift->mission ?? null;
                $missionType = $mission->type ?? 'VOLUNTEER';
                $typeLabel = $mission ? $mission->type_label : 'Εθελοντική';
                
                return [
                    'mission' => $mission->title ?? 'Άγνωστη αποστολή',
                    'mission_type' => $missionType,
                    'mission_type_label' => $typeLabel,
                    'shift' => $participation->shift->title ?? 'Βάρδια',
                    'date' => $participation->shift->start_time->format('d/m/Y'),
                    'start_time' => $participation->shift->start_time->format('H:i'),
                    'end_time' => $participation->shift->end_time->format('H:i'),
                    'hours' => $hours,
                    'location' => $participation->shift->mission->location ?? '',
                ];
            });
    }
}
