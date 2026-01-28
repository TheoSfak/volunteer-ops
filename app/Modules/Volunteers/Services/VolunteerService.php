<?php

namespace App\Modules\Volunteers\Services;

use App\Models\User;
use App\Modules\Volunteers\Models\VolunteerProfile;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VolunteerService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Λήψη όλων των εθελοντών με pagination.
     */
    public function getAll(array $filters = []): array
    {
        $query = User::with(['volunteerProfile', 'department'])
            ->where('role', User::ROLE_VOLUNTEER)
            ->whereHas('volunteerProfile');

        // Φιλτράρισμα ανά βαθμό
        if (!empty($filters['rank'])) {
            $query->whereHas('volunteerProfile', function ($q) use ($filters) {
                $q->where('rank', $filters['rank']);
            });
        }

        // Φιλτράρισμα ανά τμήμα
        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        // Αναζήτηση ονόματος
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        $perPage = $filters['per_page'] ?? 15;
        $paginated = $query->paginate($perPage);

        return [
            'data' => $paginated->getCollection()->map(fn($user) => $this->formatVolunteer($user))->toArray(),
            'total' => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
        ];
    }

    /**
     * Λήψη εθελοντή με ID.
     */
    public function getById(int $id): ?array
    {
        $user = User::with(['volunteerProfile', 'department', 'participationRequests.shift.mission'])
            ->where('role', User::ROLE_VOLUNTEER)
            ->find($id);

        if (!$user || !$user->volunteerProfile) {
            return null;
        }

        return $this->formatVolunteer($user, true);
    }

    /**
     * Ενημέρωση εθελοντή.
     */
    public function update(int $id, array $data): array
    {
        $user = User::with('volunteerProfile')->findOrFail($id);
        $profile = $user->volunteerProfile;
        
        $beforeUser = $user->toArray();
        $beforeProfile = $profile->toArray();

        DB::transaction(function () use ($user, $profile, $data) {
            // Ενημέρωση βασικών στοιχείων χρήστη
            if (isset($data['name'])) {
                $user->update(['name' => $data['name']]);
            }
            if (isset($data['phone'])) {
                $user->update(['phone' => $data['phone']]);
            }

            // Ενημέρωση προφίλ εθελοντή
            $profileData = [];
            if (isset($data['rank'])) {
                $profileData['rank'] = $data['rank'];
            }
            if (isset($data['medical_notes'])) {
                $profileData['medical_notes'] = $data['medical_notes'];
            }
            if (isset($data['specialties'])) {
                $profileData['specialties'] = $data['specialties'];
            }
            if (isset($data['certifications'])) {
                $profileData['certifications'] = $data['certifications'];
            }
            if (isset($data['date_of_birth'])) {
                $profileData['date_of_birth'] = $data['date_of_birth'];
            }
            if (isset($data['blood_type'])) {
                $profileData['blood_type'] = $data['blood_type'];
            }
            if (isset($data['address'])) {
                $profileData['address'] = $data['address'];
            }
            if (isset($data['city'])) {
                $profileData['city'] = $data['city'];
            }
            if (isset($data['postal_code'])) {
                $profileData['postal_code'] = $data['postal_code'];
            }
            if (!empty($profileData)) {
                $profile->update($profileData);
            }
        });

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΕΝΗΜΕΡΩΣΗ_ΕΘΕΛΟΝΤΗ',
            entityType: 'VolunteerProfile',
            entityId: $id,
            before: array_merge($beforeUser, $beforeProfile),
            after: array_merge($user->fresh()->toArray(), $profile->fresh()->toArray())
        );

        return $this->formatVolunteer($user->fresh(['volunteerProfile', 'department']));
    }

    /**
     * Αναζήτηση εθελοντών.
     */
    public function search(string $query): array
    {
        if (strlen($query) < 2) {
            return [];
        }

        $users = User::with(['volunteerProfile', 'department'])
            ->where('role', User::ROLE_VOLUNTEER)
            ->whereHas('volunteerProfile')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', '%' . $query . '%')
                  ->orWhere('email', 'like', '%' . $query . '%')
                  ->orWhereHas('volunteerProfile', function ($q2) use ($query) {
                      $q2->where('registry_no', 'like', '%' . $query . '%');
                  });
            })
            ->limit(20)
            ->get();

        return $users->map(fn($user) => $this->formatVolunteer($user))->toArray();
    }

    /**
     * Στατιστικά εθελοντή.
     */
    public function getStats(int $id): ?array
    {
        $user = User::with('volunteerProfile')->find($id);

        if (!$user || !$user->volunteerProfile) {
            return null;
        }

        $approved = ParticipationRequest::where('volunteer_id', $id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->count();

        $pending = ParticipationRequest::where('volunteer_id', $id)
            ->where('status', ParticipationRequest::STATUS_PENDING)
            ->count();

        $completed = ParticipationRequest::where('volunteer_id', $id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', function ($q) {
                $q->where('end_time', '<', now());
            })
            ->count();

        $totalHours = ParticipationRequest::where('volunteer_id', $id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', function ($q) {
                $q->where('end_time', '<', now());
            })
            ->with('shift')
            ->get()
            ->sum(function ($participation) {
                if (!$participation->shift->start_time || !$participation->shift->end_time) {
                    return 0;
                }
                return $participation->shift->start_time->diffInHours($participation->shift->end_time);
            });

        return [
            'συμμετοχές_εγκεκριμένες' => $approved,
            'συμμετοχές_εκκρεμείς' => $pending,
            'ολοκληρωμένες_βάρδιες' => $completed,
            'συνολικές_ώρες' => $totalHours,
            'μέλος_από' => $user->volunteerProfile->joined_at,
        ];
    }

    /**
     * Ιστορικό συμμετοχών.
     */
    public function getHistory(int $id): ?array
    {
        $user = User::find($id);

        if (!$user) {
            return null;
        }

        $participations = ParticipationRequest::with(['shift.mission'])
            ->where('volunteer_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $participations->map(function ($p) {
            return [
                'id' => $p->id,
                'βάρδια' => [
                    'id' => $p->shift->id,
                    'τίτλος' => $p->shift->title,
                    'έναρξη' => $p->shift->start_time,
                    'λήξη' => $p->shift->end_time,
                ],
                'αποστολή' => [
                    'id' => $p->shift->mission->id,
                    'τίτλος' => $p->shift->mission->title,
                ],
                'κατάσταση' => $p->status,
                'κατάσταση_ετικέτα' => $p->status_label,
                'αίτηση_στις' => $p->created_at,
                'απόφαση_στις' => $p->decided_at,
            ];
        })->toArray();
    }

    /**
     * Μορφοποίηση εθελοντή για API.
     */
    protected function formatVolunteer(User $user, bool $detailed = false): array
    {
        $profile = $user->volunteerProfile;
        
        $data = [
            'id' => $user->id,
            'όνομα' => $user->name,
            'email' => $user->email,
            'τηλέφωνο' => $user->phone,
            'τμήμα' => $user->department ? [
                'id' => $user->department->id,
                'όνομα' => $user->department->name,
            ] : null,
            'βαθμός' => $profile?->rank,
            'βαθμός_ετικέτα' => $profile?->rank_label,
            'ειδικότητες' => $profile?->specialties,
            'πιστοποιήσεις' => $profile?->certifications,
        ];

        if ($detailed) {
            $data['ημερομηνία_γέννησης'] = $profile?->date_of_birth;
            $data['ομάδα_αίματος'] = $profile?->blood_type;
            $data['ιατρικές_σημειώσεις'] = $profile?->medical_notes;
            $data['επαφή_έκτακτης_ανάγκης'] = $profile?->emergency_contact;
            $data['διαθεσιμότητα'] = $profile?->availability;
            $data['διεύθυνση'] = $profile?->address;
            $data['πόλη'] = $profile?->city;
            $data['ταχυδρομικός_κώδικας'] = $profile?->postal_code;
            $data['ενεργός'] = $user->is_active;
            $data['δημιουργήθηκε'] = $user->created_at;
        }

        return $data;
    }
}
