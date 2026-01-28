<?php

namespace App\Modules\Shifts\Services;

use App\Modules\Shifts\Models\Shift;
use App\Modules\Missions\Models\Mission;
use App\Modules\Shifts\Events\ShiftFull;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Support\Facades\Auth;

class ShiftService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Λήψη βαρδιών αποστολής.
     */
    public function getByMission(int $missionId): array
    {
        $shifts = Shift::with(['leader', 'participations'])
            ->where('mission_id', $missionId)
            ->orderBy('start_time')
            ->get();

        return $shifts->map(fn($s) => $this->formatShift($s))->toArray();
    }

    /**
     * Λήψη βάρδιας με ID.
     */
    public function getById(int $id): ?array
    {
        $shift = Shift::with(['mission', 'leader', 'participations.user'])
            ->find($id);

        if (!$shift) {
            return null;
        }

        return $this->formatShift($shift, true);
    }

    /**
     * Δημιουργία νέας βάρδιας.
     */
    public function create(int $missionId, array $data): array
    {
        $mission = Mission::findOrFail($missionId);

        $shift = Shift::create([
            'mission_id' => $missionId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'max_capacity' => $data['max_capacity'] ?? 1,
            'current_count' => 0,
            'leader_id' => $data['leader_id'] ?? null,
            'location' => $data['location'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => Shift::STATUS_OPEN,
        ]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΔΗΜΙΟΥΡΓΙΑ_ΒΑΡΔΙΑΣ',
            entityType: 'Shift',
            entityId: $shift->id,
            after: $shift->toArray()
        );

        return $this->formatShift($shift->load(['mission', 'leader']));
    }

    /**
     * Ενημέρωση βάρδιας.
     */
    public function update(int $id, array $data): array
    {
        $shift = Shift::findOrFail($id);
        $before = $shift->toArray();

        $shift->update($data);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΕΝΗΜΕΡΩΣΗ_ΒΑΡΔΙΑΣ',
            entityType: 'Shift',
            entityId: $shift->id,
            before: $before,
            after: $shift->toArray()
        );

        return $this->formatShift($shift->fresh(['mission', 'leader']));
    }

    /**
     * Κλείδωμα βάρδιας.
     */
    public function lock(int $id): array
    {
        $shift = Shift::findOrFail($id);

        if ($shift->status === Shift::STATUS_LOCKED) {
            return [
                'success' => false,
                'message' => 'Η βάρδια είναι ήδη κλειδωμένη.',
            ];
        }

        if ($shift->status === Shift::STATUS_CANCELED) {
            return [
                'success' => false,
                'message' => 'Δεν μπορείτε να κλειδώσετε ακυρωμένη βάρδια.',
            ];
        }

        $before = $shift->toArray();
        $shift->update(['status' => Shift::STATUS_LOCKED]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΚΛΕΙΔΩΜΑ_ΒΑΡΔΙΑΣ',
            entityType: 'Shift',
            entityId: $shift->id,
            before: $before,
            after: $shift->toArray()
        );

        return [
            'success' => true,
            'shift' => $this->formatShift($shift->fresh(['mission', 'leader'])),
        ];
    }

    /**
     * Λήψη εθελοντών βάρδιας.
     */
    public function getVolunteers(int $id): ?array
    {
        $shift = Shift::with(['participations.user.volunteerProfile'])->find($id);

        if (!$shift) {
            return null;
        }

        return $shift->participations
            ->where('status', 'APPROVED')
            ->map(function ($p) {
                return [
                    'id' => $p->user->id,
                    'όνομα' => $p->user->name,
                    'email' => $p->user->email,
                    'τηλέφωνο' => $p->user->phone,
                    'αριθμός_μητρώου' => $p->user->volunteerProfile?->registry_no,
                    'βαθμός' => $p->user->volunteerProfile?->rank_label,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Ενημέρωση κατάστασης μετά από έγκριση συμμετοχής.
     */
    public function checkAndUpdateStatus(Shift $shift): void
    {
        $shift->updateStatusBasedOnCapacity();

        if ($shift->status === Shift::STATUS_FULL) {
            event(new ShiftFull($shift));
        }
    }

    /**
     * Μορφοποίηση βάρδιας για API.
     */
    protected function formatShift(Shift $shift, bool $detailed = false): array
    {
        $data = [
            'id' => $shift->id,
            'τίτλος' => $shift->title,
            'περιγραφή' => $shift->description,
            'έναρξη' => $shift->start_time,
            'λήξη' => $shift->end_time,
            'τοποθεσία' => $shift->location,
            'μέγιστη_χωρητικότητα' => $shift->max_capacity,
            'τρέχουσα_συμμετοχή' => $shift->current_count,
            'διαθέσιμες_θέσεις' => $shift->max_capacity - $shift->current_count,
            'κατάσταση' => $shift->status,
            'κατάσταση_ετικέτα' => $shift->status_label,
            'αρχηγός' => $shift->leader ? [
                'id' => $shift->leader->id,
                'όνομα' => $shift->leader->name,
            ] : null,
            'αποστολή' => $shift->mission ? [
                'id' => $shift->mission->id,
                'τίτλος' => $shift->mission->title,
            ] : null,
        ];

        if ($detailed) {
            $data['συμμετοχές'] = [
                'εγκεκριμένες' => $shift->participations->where('status', 'APPROVED')->count(),
                'εκκρεμείς' => $shift->participations->where('status', 'PENDING')->count(),
                'απορριφθείσες' => $shift->participations->where('status', 'REJECTED')->count(),
            ];
            $data['δημιουργήθηκε'] = $shift->created_at;
            $data['ενημερώθηκε'] = $shift->updated_at;
        }

        return $data;
    }
}
