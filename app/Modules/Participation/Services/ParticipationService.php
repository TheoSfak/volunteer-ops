<?php

namespace App\Modules\Participation\Services;

use App\Models\User;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Participation\Events\ParticipationRequested;
use App\Modules\Participation\Events\ParticipationApproved;
use App\Modules\Participation\Events\ParticipationRejected;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Shifts\Services\ShiftService;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ParticipationService
{
    public function __construct(
        protected AuditService $auditService,
        protected ShiftService $shiftService
    ) {}

    /**
     * Υποβολή αίτησης συμμετοχής.
     */
    public function apply(int $shiftId, int $userId, array $data): array
    {
        $shift = Shift::with('mission')->find($shiftId);

        if (!$shift) {
            return [
                'success' => false,
                'message' => 'Η βάρδια δεν βρέθηκε.',
            ];
        }

        // Έλεγχος αν η βάρδια δέχεται αιτήσεις
        if (!$shift->acceptsRequests()) {
            return [
                'success' => false,
                'message' => 'Αυτή η βάρδια δεν δέχεται αιτήσεις συμμετοχής.',
            ];
        }

        // Έλεγχος για υπάρχουσα αίτηση
        $existing = ParticipationRequest::where('shift_id', $shiftId)
            ->where('volunteer_id', $userId)
            ->whereIn('status', [
                ParticipationRequest::STATUS_PENDING,
                ParticipationRequest::STATUS_APPROVED,
            ])
            ->exists();

        if ($existing) {
            return [
                'success' => false,
                'message' => 'Έχετε ήδη υποβάλει αίτηση για αυτή τη βάρδια.',
            ];
        }

        // Έλεγχος για επικαλυπτόμενες εγκεκριμένες βάρδιες
        $overlapping = $this->hasOverlappingShift($userId, $shift);
        if ($overlapping) {
            return [
                'success' => false,
                'message' => 'Έχετε ήδη εγκεκριμένη συμμετοχή σε βάρδια που επικαλύπτεται χρονικά.',
            ];
        }

        $participation = ParticipationRequest::create([
            'shift_id' => $shiftId,
            'volunteer_id' => $userId,
            'notes' => $data['notes'] ?? null,
            'status' => ParticipationRequest::STATUS_PENDING,
        ]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΑΙΤΗΣΗ_ΣΥΜΜΕΤΟΧΗΣ',
            entityType: 'ParticipationRequest',
            entityId: $participation->id,
            after: $participation->toArray()
        );

        event(new ParticipationRequested($participation));

        return [
            'success' => true,
            'participation' => $this->formatParticipation($participation->load(['shift.mission', 'user'])),
        ];
    }

    /**
     * Έγκριση αίτησης.
     */
    public function approve(int $id): array
    {
        $participation = ParticipationRequest::with(['shift', 'user'])->findOrFail($id);

        if (!$participation->isPending()) {
            return [
                'success' => false,
                'message' => 'Μόνο εκκρεμείς αιτήσεις μπορούν να εγκριθούν.',
            ];
        }

        // Έλεγχος διαθεσιμότητας θέσεων
        if ($participation->shift->available_slots <= 0) {
            return [
                'success' => false,
                'message' => 'Δεν υπάρχουν διαθέσιμες θέσεις σε αυτή τη βάρδια.',
            ];
        }

        // Έλεγχος για επικαλυπτόμενες βάρδιες
        if ($this->hasOverlappingShift($participation->user_id, $participation->shift)) {
            return [
                'success' => false,
                'message' => 'Ο εθελοντής έχει ήδη εγκεκριμένη συμμετοχή σε επικαλυπτόμενη βάρδια.',
            ];
        }

        $before = $participation->toArray();

        DB::transaction(function () use ($participation) {
            $participation->update([
                'status' => ParticipationRequest::STATUS_APPROVED,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
            ]);

            // Ενημέρωση κατάστασης βάρδιας
            $this->shiftService->checkAndUpdateStatus($participation->shift);
        });

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΕΓΚΡΙΣΗ_ΣΥΜΜΕΤΟΧΗΣ',
            entityType: 'ParticipationRequest',
            entityId: $participation->id,
            before: $before,
            after: $participation->fresh()->toArray()
        );

        event(new ParticipationApproved($participation));

        return [
            'success' => true,
            'participation' => $this->formatParticipation($participation->fresh(['shift.mission', 'user', 'decider'])),
        ];
    }

    /**
     * Απόρριψη αίτησης.
     */
    public function reject(int $id, array $data = []): array
    {
        $participation = ParticipationRequest::with(['shift', 'user'])->findOrFail($id);

        if (!$participation->isPending()) {
            return [
                'success' => false,
                'message' => 'Μόνο εκκρεμείς αιτήσεις μπορούν να απορριφθούν.',
            ];
        }

        $before = $participation->toArray();

        $participation->update([
            'status' => ParticipationRequest::STATUS_REJECTED,
            'decided_by' => Auth::id(),
            'decided_at' => now(),
            'rejection_reason' => $data['reason'] ?? null,
        ]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΑΠΟΡΡΙΨΗ_ΣΥΜΜΕΤΟΧΗΣ',
            entityType: 'ParticipationRequest',
            entityId: $participation->id,
            before: $before,
            after: $participation->toArray()
        );

        event(new ParticipationRejected($participation));

        return [
            'success' => true,
            'participation' => $this->formatParticipation($participation->fresh(['shift.mission', 'user', 'decider'])),
        ];
    }

    /**
     * Ακύρωση αίτησης.
     */
    public function cancel(int $id): array
    {
        $participation = ParticipationRequest::with(['shift', 'user'])->findOrFail($id);

        if (!in_array($participation->status, [
            ParticipationRequest::STATUS_PENDING,
            ParticipationRequest::STATUS_APPROVED,
        ])) {
            return [
                'success' => false,
                'message' => 'Αυτή η αίτηση δεν μπορεί να ακυρωθεί.',
            ];
        }

        $before = $participation->toArray();
        $isOwner = $participation->volunteer_id === Auth::id();

        DB::transaction(function () use ($participation, $isOwner) {
            $participation->update([
                'status' => $isOwner 
                    ? ParticipationRequest::STATUS_CANCELED_BY_USER 
                    : ParticipationRequest::STATUS_CANCELED_BY_ADMIN,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
            ]);

            // Ενημέρωση κατάστασης βάρδιας αν ήταν εγκεκριμένη
            if ($participation->getOriginal('status') === ParticipationRequest::STATUS_APPROVED) {
                $participation->shift->updateStatusBasedOnCapacity();
            }
        });

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΑΚΥΡΩΣΗ_ΣΥΜΜΕΤΟΧΗΣ',
            entityType: 'ParticipationRequest',
            entityId: $participation->id,
            before: $before,
            after: $participation->fresh()->toArray()
        );

        return [
            'success' => true,
            'participation' => $this->formatParticipation($participation->fresh(['shift.mission', 'user'])),
        ];
    }

    /**
     * Λήψη συμμετοχών χρήστη.
     */
    public function getByUser(int $userId): array
    {
        $participations = ParticipationRequest::with(['shift.mission', 'decider'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        return $participations->map(fn($p) => $this->formatParticipation($p))->toArray();
    }

    /**
     * Λήψη εκκρεμών αιτήσεων για διαχειριστή.
     */
    public function getPending(User $user): array
    {
        $query = ParticipationRequest::with(['shift.mission.department', 'user.volunteerProfile'])
            ->where('status', ParticipationRequest::STATUS_PENDING);

        // Φιλτράρισμα ανάλογα με τον ρόλο
        if ($user->hasRole(User::ROLE_DEPARTMENT_ADMIN)) {
            $query->whereHas('shift.mission', function ($q) use ($user) {
                $q->where('department_id', $user->department_id);
            });
        } elseif ($user->hasRole(User::ROLE_SHIFT_LEADER)) {
            $query->whereHas('shift', function ($q) use ($user) {
                $q->where('leader_user_id', $user->id);
            });
        }

        $participations = $query->orderBy('created_at', 'asc')->get();

        return $participations->map(fn($p) => $this->formatParticipation($p, true))->toArray();
    }

    /**
     * Λήψη αίτησης με ID.
     */
    public function getById(int $id): ?array
    {
        $participation = ParticipationRequest::with(['shift.mission', 'user.volunteerProfile', 'decider'])
            ->find($id);

        if (!$participation) {
            return null;
        }

        return $this->formatParticipation($participation, true);
    }

    /**
     * Έλεγχος για επικαλυπτόμενες βάρδιες.
     */
    protected function hasOverlappingShift(int $userId, Shift $shift): bool
    {
        return ParticipationRequest::where('volunteer_id', $userId)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', function ($q) use ($shift) {
                $q->where('id', '!=', $shift->id)
                  ->where(function ($q2) use ($shift) {
                      $q2->where('start_time', '<', $shift->end_time)
                         ->where('end_time', '>', $shift->start_time);
                  });
            })
            ->exists();
    }

    /**
     * Μορφοποίηση αίτησης για API.
     */
    protected function formatParticipation(ParticipationRequest $participation, bool $detailed = false): array
    {
        $data = [
            'id' => $participation->id,
            'κατάσταση' => $participation->status,
            'κατάσταση_ετικέτα' => $participation->status_label,
            'σημειώσεις' => $participation->notes,
            'υποβλήθηκε' => $participation->created_at,
            'βάρδια' => $participation->shift ? [
                'id' => $participation->shift->id,
                'τίτλος' => $participation->shift->title,
                'έναρξη' => $participation->shift->start_time,
                'λήξη' => $participation->shift->end_time,
                'κατάσταση' => $participation->shift->status,
            ] : null,
            'αποστολή' => $participation->shift?->mission ? [
                'id' => $participation->shift->mission->id,
                'τίτλος' => $participation->shift->mission->title,
            ] : null,
        ];

        if ($detailed) {
            $data['εθελοντής'] = $participation->user ? [
                'id' => $participation->user->id,
                'όνομα' => $participation->user->name,
                'email' => $participation->user->email,
                'τηλέφωνο' => $participation->user->phone,
                'αριθμός_μητρώου' => $participation->user->volunteerProfile?->registry_no,
            ] : null;

            $data['απόφαση'] = $participation->decider ? [
                'από' => $participation->decider->name,
                'στις' => $participation->decided_at,
            ] : null;
        }

        return $data;
    }
}
