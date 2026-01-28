<?php

namespace App\Modules\Missions\Services;

use App\Modules\Missions\Models\Mission;
use App\Modules\Missions\Events\MissionPublished;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MissionService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Λήψη όλων των αποστολών με pagination.
     */
    public function getAll(array $filters = []): array
    {
        $query = Mission::with(['department', 'creator']);

        // Φιλτράρισμα ανά κατάσταση
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Φιλτράρισμα ανά τύπο
        if (!empty($filters['mission_type'])) {
            $query->where('mission_type', $filters['mission_type']);
        }

        // Φιλτράρισμα ανά τμήμα
        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        // Αναζήτηση τίτλου
        if (!empty($filters['search'])) {
            $query->where('title', 'like', '%' . $filters['search'] . '%');
        }

        // Ταξινόμηση
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = $filters['per_page'] ?? 15;
        $paginated = $query->paginate($perPage);

        return [
            'data' => $paginated->getCollection()->map(fn($m) => $this->formatMission($m))->toArray(),
            'total' => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
        ];
    }

    /**
     * Λήψη αποστολής με ID.
     */
    public function getById(int $id): ?array
    {
        $mission = Mission::with(['department', 'creator', 'shifts.participations'])
            ->find($id);

        if (!$mission) {
            return null;
        }

        return $this->formatMission($mission, true);
    }

    /**
     * Δημιουργία νέας αποστολής.
     */
    public function create(array $data): array
    {
        $mission = Mission::create([
            'department_id' => $data['department_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'location' => $data['location'] ?? null,
            'location_details' => $data['location_details'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'start_datetime' => $data['start_datetime'],
            'end_datetime' => $data['end_datetime'],
            'requirements' => $data['requirements'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_urgent' => $data['is_urgent'] ?? false,
            'status' => $data['status'] ?? Mission::STATUS_DRAFT,
            'created_by' => $data['created_by'] ?? Auth::id(),
        ]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΔΗΜΙΟΥΡΓΙΑ_ΑΠΟΣΤΟΛΗΣ',
            entityType: 'Mission',
            entityId: $mission->id,
            after: $mission->toArray()
        );

        return $this->formatMission($mission->load(['department', 'creator']));
    }

    /**
     * Δημιουργία αποστολής με αυτόματη βάρδια.
     */
    public function createWithDefaultShift(array $data): array
    {
        $mission = $this->create($data);
        
        // Λήψη default shift capacity από settings, με fallback στο config
        $defaultCapacity = (int) \App\Models\Setting::get(
            'default_shift_capacity', 
            config('volunteerops.shifts.default_capacity', 20)
        );
        
        \App\Modules\Shifts\Models\Shift::create([
            'mission_id' => $mission['id'],
            'title' => 'Γενική Βάρδια',
            'description' => 'Κύρια βάρδια της αποστολής',
            'start_time' => $data['start_datetime'],
            'end_time' => $data['end_datetime'],
            'max_capacity' => $defaultCapacity,
        ]);
        
        return $mission;
    }

    /**
     * Ενημέρωση αποστολής.
     */
    public function update(int $id, array $data): array
    {
        $mission = Mission::findOrFail($id);
        $before = $mission->toArray();

        $mission->update($data);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΕΝΗΜΕΡΩΣΗ_ΑΠΟΣΤΟΛΗΣ',
            entityType: 'Mission',
            entityId: $mission->id,
            before: $before,
            after: $mission->toArray()
        );

        return $this->formatMission($mission->fresh(['department', 'creator']));
    }

    /**
     * Δημοσίευση αποστολής.
     */
    public function publish(int $id): array
    {
        $mission = Mission::findOrFail($id);

        if ($mission->status !== Mission::STATUS_DRAFT) {
            return [
                'success' => false,
                'message' => 'Μόνο πρόχειρες αποστολές μπορούν να δημοσιευτούν.',
            ];
        }

        $before = $mission->toArray();
        $mission->update(['status' => Mission::STATUS_OPEN]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΔΗΜΟΣΙΕΥΣΗ_ΑΠΟΣΤΟΛΗΣ',
            entityType: 'Mission',
            entityId: $mission->id,
            before: $before,
            after: $mission->toArray()
        );

        // Εκπομπή γεγονότος
        event(new MissionPublished($mission));

        return [
            'success' => true,
            'mission' => $this->formatMission($mission->fresh(['department', 'creator'])),
        ];
    }

    /**
     * Κλείσιμο αποστολής.
     */
    public function close(int $id): array
    {
        $mission = Mission::findOrFail($id);

        if ($mission->status !== Mission::STATUS_OPEN) {
            return [
                'success' => false,
                'message' => 'Μόνο ανοιχτές αποστολές μπορούν να κλείσουν.',
            ];
        }

        $before = $mission->toArray();
        $mission->update(['status' => Mission::STATUS_CLOSED]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΚΛΕΙΣΙΜΟ_ΑΠΟΣΤΟΛΗΣ',
            entityType: 'Mission',
            entityId: $mission->id,
            before: $before,
            after: $mission->toArray()
        );

        return [
            'success' => true,
            'mission' => $this->formatMission($mission->fresh(['department', 'creator'])),
        ];
    }

    /**
     * Ακύρωση αποστολής.
     */
    public function cancel(int $id): array
    {
        $mission = Mission::findOrFail($id);

        if (in_array($mission->status, [Mission::STATUS_COMPLETED, Mission::STATUS_CANCELED])) {
            return [
                'success' => false,
                'message' => 'Αυτή η αποστολή δεν μπορεί να ακυρωθεί.',
            ];
        }

        $before = $mission->toArray();
        $mission->update(['status' => Mission::STATUS_CANCELED]);

        // Ακύρωση όλων των βαρδιών
        $mission->shifts()->update(['status' => 'CANCELED']);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΑΚΥΡΩΣΗ_ΑΠΟΣΤΟΛΗΣ',
            entityType: 'Mission',
            entityId: $mission->id,
            before: $before,
            after: $mission->toArray()
        );

        return [
            'success' => true,
            'mission' => $this->formatMission($mission->fresh(['department', 'creator'])),
        ];
    }

    /**
     * Στατιστικά αποστολής.
     */
    public function getStats(int $id): ?array
    {
        $mission = Mission::with(['shifts.participations'])->find($id);

        if (!$mission) {
            return null;
        }

        $totalCapacity = $mission->shifts->sum('capacity');
        $approvedCount = $mission->shifts->sum(function ($shift) {
            return $shift->participations->where('status', 'APPROVED')->count();
        });
        $pendingCount = $mission->shifts->sum(function ($shift) {
            return $shift->participations->where('status', 'PENDING')->count();
        });

        return [
            'αποστολή_id' => $mission->id,
            'τίτλος' => $mission->title,
            'κατάσταση' => $mission->status,
            'κατάσταση_ετικέτα' => $mission->status_label,
            'συνολική_χωρητικότητα' => $totalCapacity,
            'εγκεκριμένες_συμμετοχές' => $approvedCount,
            'εκκρεμείς_αιτήσεις' => $pendingCount,
            'ποσοστό_κάλυψης' => $totalCapacity > 0 ? round(($approvedCount / $totalCapacity) * 100, 2) : 0,
            'πλήθος_βαρδιών' => $mission->shifts->count(),
        ];
    }

    /**
     * Μορφοποίηση αποστολής για API.
     */
    protected function formatMission(Mission $mission, bool $detailed = false): array
    {
        $data = [
            'id' => $mission->id,
            'τίτλος' => $mission->title,
            'περιγραφή' => $mission->description,
            'τύπος' => $mission->type,
            'τύπος_ετικέτα' => $mission->type_label,
            'τοποθεσία' => $mission->location,
            'λεπτομέρειες_τοποθεσίας' => $mission->location_details,
            'συντεταγμένες' => [
                'lat' => $mission->latitude,
                'lng' => $mission->longitude,
            ],
            'ημερομηνία_έναρξης' => $mission->start_datetime,
            'ημερομηνία_λήξης' => $mission->end_datetime,
            'επείγουσα' => $mission->is_urgent,
            'κατάσταση' => $mission->status,
            'κατάσταση_ετικέτα' => $mission->status_label,
            'τμήμα' => $mission->department ? [
                'id' => $mission->department->id,
                'όνομα' => $mission->department->name,
            ] : null,
            'δημιουργός' => $mission->creator ? [
                'id' => $mission->creator->id,
                'όνομα' => $mission->creator->name,
            ] : null,
            'δημιουργήθηκε' => $mission->created_at,
            'ενημερώθηκε' => $mission->updated_at,
        ];

        if ($detailed) {
            $data['ποσοστό_κάλυψης'] = $mission->getCoveragePercentage();
            $data['βάρδιες'] = $mission->shifts->map(function ($shift) {
                return [
                    'id' => $shift->id,
                    'τίτλος' => $shift->title,
                    'έναρξη' => $shift->start_dt,
                    'λήξη' => $shift->end_dt,
                    'χωρητικότητα' => $shift->capacity,
                    'κατάσταση' => $shift->status,
                    'εγκεκριμένες' => $shift->participations->where('status', 'APPROVED')->count(),
                ];
            })->toArray();
        }

        return $data;
    }
}
