<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Modules\Directory\Models\Department;
use App\Modules\Volunteers\Models\VolunteerProfile;
use App\Modules\Participation\Models\ParticipationRequest;
use Carbon\Carbon;

class VolunteerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'VOLUNTEER')
            ->with('department')
            ->withCount('participationRequests');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $volunteers = $query->latest()->paginate(15);
        $departments = Department::where('is_active', true)->get();

        return view('volunteers.index', compact('volunteers', 'departments'));
    }

    public function create()
    {
        $departments = Department::where('is_active', true)->get();
        $canCreateAdmin = auth()->user()->hasRole(User::ROLE_SYSTEM_ADMIN);
        $roles = $canCreateAdmin ? [
            User::ROLE_VOLUNTEER => 'Εθελοντής',
            User::ROLE_SHIFT_LEADER => 'Υπεύθυνος Βάρδιας',
            User::ROLE_DEPARTMENT_ADMIN => 'Διαχειριστής Τμήματος',
            User::ROLE_SYSTEM_ADMIN => 'Διαχειριστής Συστήματος',
        ] : [];
        
        return view('volunteers.create', compact('departments', 'canCreateAdmin', 'roles'));
    }

    public function store(Request $request)
    {
        $canCreateAdmin = auth()->user()->hasRole(User::ROLE_SYSTEM_ADMIN);
        
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'department_id' => 'nullable|exists:departments,id',
            'password' => 'required|string|min:8|confirmed',
        ];
        
        // Αν είναι SYSTEM_ADMIN, επιτρέπουμε επιλογή ρόλου
        if ($canCreateAdmin) {
            $rules['role'] = 'nullable|string|in:' . implode(',', [
                User::ROLE_VOLUNTEER,
                User::ROLE_SHIFT_LEADER,
                User::ROLE_DEPARTMENT_ADMIN,
                User::ROLE_SYSTEM_ADMIN,
            ]);
        }
        
        $validated = $request->validate($rules);
        
        // Ορισμός ρόλου - default VOLUNTEER αν δεν επιλεχθεί
        $role = ($canCreateAdmin && !empty($validated['role'])) 
            ? $validated['role'] 
            : User::ROLE_VOLUNTEER;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => $role,
            'is_active' => true,
        ]);

        // Δημιουργία προφίλ μόνο για εθελοντές
        if ($role === User::ROLE_VOLUNTEER) {
            VolunteerProfile::create([
                'user_id' => $user->id,
                'date_of_birth' => $request->input('date_of_birth'),
                'blood_type' => $request->input('blood_type'),
                'address' => $request->input('address'),
                'city' => $request->input('city'),
                'postal_code' => $request->input('postal_code'),
                'emergency_contact' => $request->input('emergency_contact'),
                'specialties' => $request->input('specialties'),
            ]);
        }

        $roleLabels = [
            User::ROLE_SYSTEM_ADMIN => 'Διαχειριστής Συστήματος',
            User::ROLE_DEPARTMENT_ADMIN => 'Διαχειριστής Τμήματος',
            User::ROLE_SHIFT_LEADER => 'Υπεύθυνος Βάρδιας',
            User::ROLE_VOLUNTEER => 'Εθελοντής',
        ];

        return redirect()->route('volunteers.show', $user)
            ->with('success', 'Ο χρήστης (' . ($roleLabels[$role] ?? $role) . ') δημιουργήθηκε επιτυχώς.');
    }

    public function show(Request $request, User $volunteer)
    {
        $volunteer->load(['department', 'volunteerProfile', 'participationRequests.shift.mission', 'achievements']);
        
        $currentYear = now()->year;
        $selectedYear = (int) $request->get('year', $currentYear);
        
        // Calculate extended statistics με επιλεγμένο έτος
        $stats = $this->calculateVolunteerStats($volunteer, $selectedYear);
        
        // Διαθέσιμα έτη για επιλογή
        $availableYears = $this->getVolunteerAvailableYears($volunteer);
        
        return view('volunteers.show', compact('volunteer', 'stats', 'selectedYear', 'currentYear', 'availableYears'));
    }

    protected function getVolunteerAvailableYears(User $volunteer): array
    {
        $years = ParticipationRequest::where('volunteer_id', $volunteer->id)
            ->whereHas('shift')
            ->with('shift')
            ->get()
            ->pluck('shift.start_time')
            ->filter()
            ->map(fn($date) => Carbon::parse($date)->year)
            ->unique()
            ->sort()
            ->values()
            ->toArray();
            
        // Προσθήκη τρέχοντος έτους αν δεν υπάρχει
        if (!in_array(now()->year, $years)) {
            $years[] = now()->year;
        }
        
        rsort($years);
        return $years;
    }

    protected function calculateVolunteerStats(User $volunteer, ?int $year = null): array
    {
        $year = $year ?? now()->year;
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
        
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        // Φιλτράρισμα συμμετοχών με βάση το έτος
        $participations = $volunteer->participationRequests()
            ->whereHas('shift', fn($q) => $q->whereBetween('start_time', [$startOfYear, $endOfYear]))
            ->with('shift.mission')
            ->get();

        $approved = $participations->where('status', 'APPROVED');
        $pending = $participations->where('status', 'PENDING');
        $rejected = $participations->where('status', 'REJECTED');

        // Hours calculations
        $totalHours = 0;
        $hoursThisMonth = 0;
        $hoursThisYear = 0;
        $hoursLastMonth = 0;
        $hoursByType = ['volunteer' => 0, 'medical' => 0];
        $hoursByMonth = [];
        $missionTypes = [];

        foreach ($approved as $p) {
            if ($p->shift && $p->shift->start_time && $p->shift->end_time) {
                $hours = $p->shift->end_time->diffInMinutes($p->shift->start_time) / 60;
                $totalHours += $hours;

                $shiftDate = $p->shift->start_time;
                $monthKey = $shiftDate->format('Y-m');
                $hoursByMonth[$monthKey] = ($hoursByMonth[$monthKey] ?? 0) + $hours;

                if ($shiftDate >= $startOfMonth) {
                    $hoursThisMonth += $hours;
                }
                if ($shiftDate >= $startOfYear) {
                    $hoursThisYear += $hours;
                }
                if ($shiftDate >= $startOfLastMonth && $shiftDate <= $endOfLastMonth) {
                    $hoursLastMonth += $hours;
                }

                $missionType = $p->shift->mission->type ?? 'VOLUNTEER';
                if ($missionType === 'MEDICAL') {
                    $hoursByType['medical'] += $hours;
                } else {
                    $hoursByType['volunteer'] += $hours;
                }

                // Count mission types
                $missionTypes[$missionType] = ($missionTypes[$missionType] ?? 0) + 1;
            }
        }

        // Unique missions
        $uniqueMissions = $approved
            ->map(fn($p) => $p->shift->mission_id ?? null)
            ->filter()
            ->unique()
            ->count();

        // Participation streak
        $streak = $this->calculateStreak($volunteer);

        // Average hours per participation
        $avgHoursPerParticipation = $approved->count() > 0 
            ? round($totalHours / $approved->count(), 1) 
            : 0;

        // Ranking among all volunteers
        $ranking = $this->getVolunteerRanking($volunteer);

        // Points history
        $pointsThisMonth = $volunteer->volunteerPoints()
            ->where('created_at', '>=', $startOfMonth)
            ->sum('points');
        $pointsThisYear = $volunteer->volunteerPoints()
            ->where('created_at', '>=', $startOfYear)
            ->sum('points');

        // Last participation date
        $lastParticipation = $approved
            ->filter(fn($p) => $p->shift && $p->shift->start_time)
            ->sortByDesc(fn($p) => $p->shift->start_time)
            ->first();

        // First participation date
        $firstParticipation = $approved
            ->filter(fn($p) => $p->shift && $p->shift->start_time)
            ->sortBy(fn($p) => $p->shift->start_time)
            ->first();

        // Monthly trend (last 6 months)
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $key = $month->format('Y-m');
            $monthlyTrend[] = [
                'month' => $month->translatedFormat('M'),
                'hours' => round($hoursByMonth[$key] ?? 0, 1),
            ];
        }

        // Approval rate
        $approvalRate = $participations->count() > 0
            ? round(($approved->count() / $participations->count()) * 100, 1)
            : 0;

        // Days since last activity
        $daysSinceLastActivity = $lastParticipation && $lastParticipation->shift
            ? $lastParticipation->shift->start_time->diffInDays($now)
            : null;

        // Active months (months with at least one participation)
        $activeMonths = count($hoursByMonth);

        // Busiest month
        $busiestMonth = null;
        if (!empty($hoursByMonth)) {
            $maxHoursMonth = array_search(max($hoursByMonth), $hoursByMonth);
            $busiestMonth = [
                'month' => Carbon::createFromFormat('Y-m', $maxHoursMonth)->translatedFormat('F Y'),
                'hours' => round($hoursByMonth[$maxHoursMonth], 1),
            ];
        }

        return [
            // Basic counts
            'total_participations' => $participations->count(),
            'approved_participations' => $approved->count(),
            'pending_participations' => $pending->count(),
            'rejected_participations' => $rejected->count(),
            'unique_missions' => $uniqueMissions,
            'approval_rate' => $approvalRate,

            // Hours
            'total_hours' => round($totalHours, 1),
            'hours_this_month' => round($hoursThisMonth, 1),
            'hours_last_month' => round($hoursLastMonth, 1),
            'hours_this_year' => round($hoursThisYear, 1),
            'hours_by_type' => [
                'volunteer' => round($hoursByType['volunteer'], 1),
                'medical' => round($hoursByType['medical'], 1),
            ],
            'avg_hours_per_participation' => $avgHoursPerParticipation,
            'monthly_trend' => $monthlyTrend,
            'active_months' => $activeMonths,
            'busiest_month' => $busiestMonth,

            // Points
            'total_points' => $volunteer->total_points ?? 0,
            'monthly_points' => $volunteer->monthly_points ?? 0,
            'points_this_month' => $pointsThisMonth,
            'points_this_year' => $pointsThisYear,

            // Achievements
            'achievements_count' => $volunteer->achievements->count(),
            'achievements' => $volunteer->achievements,

            // Activity
            'streak' => $streak,
            'days_since_last_activity' => $daysSinceLastActivity,
            'last_participation_date' => $lastParticipation?->shift?->start_time,
            'first_participation_date' => $firstParticipation?->shift?->start_time,

            // Ranking
            'ranking' => $ranking,

            // Member info
            'member_since' => $volunteer->created_at,
            'days_as_member' => $volunteer->created_at->diffInDays($now),

            // Mission type preference
            'mission_types' => $missionTypes,
            'preferred_type' => !empty($missionTypes) 
                ? array_search(max($missionTypes), $missionTypes) 
                : null,
        ];
    }

    protected function calculateStreak(User $volunteer): int
    {
        $months = $volunteer->participationRequests()
            ->where('status', 'APPROVED')
            ->with('shift')
            ->get()
            ->filter(fn($p) => $p->shift && $p->shift->start_time)
            ->map(fn($p) => $p->shift->start_time->format('Y-m'))
            ->unique()
            ->sort()
            ->values();

        if ($months->isEmpty()) return 0;

        $streak = 0;
        $checkMonth = Carbon::now()->format('Y-m');

        while ($months->contains($checkMonth)) {
            $streak++;
            $checkMonth = Carbon::createFromFormat('Y-m', $checkMonth)
                ->subMonth()
                ->format('Y-m');
        }

        return $streak;
    }

    protected function getVolunteerRanking(User $volunteer): array
    {
        $allVolunteers = User::where('role', 'VOLUNTEER')
            ->where('is_active', true)
            ->orderByDesc('total_points')
            ->get();

        $rank = $allVolunteers->search(fn($v) => $v->id === $volunteer->id);
        $total = $allVolunteers->count();

        // Hours ranking
        $byHours = User::where('role', 'VOLUNTEER')
            ->where('is_active', true)
            ->get()
            ->sortByDesc(fn($u) => $u->total_volunteer_hours ?? 0)
            ->values();

        $hoursRank = $byHours->search(fn($v) => $v->id === $volunteer->id);

        return [
            'points_position' => $rank !== false ? $rank + 1 : null,
            'hours_position' => $hoursRank !== false ? $hoursRank + 1 : null,
            'total_volunteers' => $total,
            'percentile' => $rank !== false && $total > 0
                ? round((($total - $rank) / $total) * 100, 0)
                : 0,
        ];
    }

    public function edit(User $volunteer)
    {
        $volunteer->load('volunteerProfile');
        $departments = Department::where('is_active', true)->get();
        return view('volunteers.edit', compact('volunteer', 'departments'));
    }

    public function update(Request $request, User $volunteer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $volunteer->id,
            'phone' => 'nullable|string|max:20',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        $volunteer->update($validated);

        $volunteer->volunteerProfile()->updateOrCreate(
            ['user_id' => $volunteer->id],
            [
                'date_of_birth' => $request->input('date_of_birth'),
                'blood_type' => $request->input('blood_type'),
                'address' => $request->input('address'),
                'city' => $request->input('city'),
                'postal_code' => $request->input('postal_code'),
                'emergency_contact' => $request->input('emergency_contact'),
                'specialties' => $request->input('specialties'),
            ]
        );

        return redirect()->route('volunteers.show', $volunteer)
            ->with('success', 'Ο εθελοντής ενημερώθηκε επιτυχώς.');
    }
}
