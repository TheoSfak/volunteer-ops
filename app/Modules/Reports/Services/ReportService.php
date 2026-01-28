<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Missions\Models\Mission;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Directory\Models\Department;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportService
{
    /**
     * Στατιστικά Dashboard.
     */
    public function getDashboardStats(?int $departmentId = null): array
    {
        $query = Mission::query();
        
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $totalMissions = $query->count();
        $activeMissions = (clone $query)->where('status', Mission::STATUS_OPEN)->count();
        $completedMissions = (clone $query)->where('status', Mission::STATUS_COMPLETED)->count();
        
        $shiftsQuery = Shift::query();
        if ($departmentId) {
            $shiftsQuery->whereHas('mission', fn($q) => $q->where('department_id', $departmentId));
        }
        
        $totalShifts = $shiftsQuery->count();
        $openShifts = (clone $shiftsQuery)->where('status', Shift::STATUS_OPEN)->count();
        
        $volunteersQuery = User::where('role', User::ROLE_VOLUNTEER);
        if ($departmentId) {
            $volunteersQuery->where('department_id', $departmentId);
        }
        $totalVolunteers = $volunteersQuery->count();
        $activeVolunteers = (clone $volunteersQuery)->where('is_active', true)->count();

        $participationsQuery = ParticipationRequest::where('status', ParticipationRequest::STATUS_APPROVED);
        $totalParticipations = $participationsQuery->count();
        
        $pendingRequests = ParticipationRequest::where('status', ParticipationRequest::STATUS_PENDING)->count();

        return [
            'αποστολές' => [
                'σύνολο' => $totalMissions,
                'ενεργές' => $activeMissions,
                'ολοκληρωμένες' => $completedMissions,
            ],
            'βάρδιες' => [
                'σύνολο' => $totalShifts,
                'ανοιχτές' => $openShifts,
            ],
            'εθελοντές' => [
                'σύνολο' => $totalVolunteers,
                'ενεργοί' => $activeVolunteers,
            ],
            'συμμετοχές' => [
                'εγκεκριμένες' => $totalParticipations,
                'εκκρεμείς' => $pendingRequests,
            ],
            'ημερομηνία_αναφοράς' => now()->toIso8601String(),
        ];
    }

    /**
     * Αναφορά αποστολών.
     */
    public function getMissionsReport(array $filters = []): array
    {
        $query = Mission::with(['department', 'createdBy', 'shifts']);

        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('start_datetime', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('end_datetime', '<=', $filters['date_to']);
        }

        $missions = $query->orderBy('start_datetime', 'desc')->get();

        return $missions->map(function ($mission) {
            $totalPositions = $mission->shifts->sum('max_capacity');
            $filledPositions = $mission->shifts->sum('current_count');
            $coverage = $totalPositions > 0 ? round(($filledPositions / $totalPositions) * 100, 2) : 0;

            return [
                'id' => $mission->id,
                'τίτλος' => $mission->title,
                'τύπος' => Mission::getTypeLabelAttribute($mission->type),
                'κατάσταση' => Mission::getStatusLabelAttribute($mission->status),
                'τμήμα' => $mission->department?->name,
                'έναρξη' => $mission->start_datetime,
                'λήξη' => $mission->end_datetime,
                'βάρδιες' => $mission->shifts->count(),
                'θέσεις_συνολικά' => $totalPositions,
                'θέσεις_καλυμμένες' => $filledPositions,
                'κάλυψη' => $coverage . '%',
            ];
        })->toArray();
    }

    /**
     * Αναφορά βαρδιών.
     */
    public function getShiftsReport(array $filters = []): array
    {
        $query = Shift::with(['mission', 'leader', 'participationRequests']);

        if (isset($filters['mission_id'])) {
            $query->where('mission_id', $filters['mission_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('start_time', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('end_time', '<=', $filters['date_to']);
        }

        $shifts = $query->orderBy('start_time', 'desc')->get();

        return $shifts->map(function ($shift) {
            return [
                'id' => $shift->id,
                'αποστολή' => $shift->mission?->title,
                'κατάσταση' => Shift::getStatusLabelAttribute($shift->status),
                'έναρξη' => $shift->start_time,
                'λήξη' => $shift->end_time,
                'μέγιστη_χωρητικότητα' => $shift->max_capacity,
                'τρέχων_αριθμός' => $shift->current_count,
                'διαθέσιμες_θέσεις' => $shift->max_capacity - $shift->current_count,
                'αρχηγός' => $shift->leader?->name,
                'τοποθεσία' => $shift->location,
            ];
        })->toArray();
    }

    /**
     * Αναφορά εθελοντών.
     */
    public function getVolunteersReport(array $filters = []): array
    {
        $query = User::where('role', User::ROLE_VOLUNTEER)
            ->with(['department', 'volunteerProfile', 'participationRequests']);

        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $volunteers = $query->get();

        return $volunteers->map(function ($volunteer) {
            $approvedParticipations = $volunteer->participationRequests
                ->where('status', ParticipationRequest::STATUS_APPROVED)
                ->count();

            return [
                'id' => $volunteer->id,
                'όνομα' => $volunteer->name,
                'email' => $volunteer->email,
                'τμήμα' => $volunteer->department?->name,
                'βαθμός' => $volunteer->volunteerProfile?->rank_label ?? 'Δόκιμος',
                'ενεργός' => $volunteer->is_active ? 'Ναι' : 'Όχι',
                'συμμετοχές' => $approvedParticipations,
                'τηλέφωνο' => $volunteer->phone,
                'εγγραφή' => $volunteer->created_at->format('d/m/Y'),
            ];
        })->toArray();
    }

    /**
     * Αναφορά συμμετοχών.
     */
    public function getParticipationsReport(array $filters = []): array
    {
        $query = ParticipationRequest::with(['volunteer', 'shift.mission']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['volunteer_id'])) {
            $query->where('volunteer_id', $filters['volunteer_id']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $participations = $query->orderBy('created_at', 'desc')->get();

        return $participations->map(function ($participation) {
            return [
                'id' => $participation->id,
                'εθελοντής' => $participation->volunteer?->name,
                'αποστολή' => $participation->shift?->mission?->title,
                'βάρδια_έναρξη' => $participation->shift?->start_time,
                'βάρδια_λήξη' => $participation->shift?->end_time,
                'κατάσταση' => ParticipationRequest::getStatusLabelAttribute($participation->status),
                'υποβλήθηκε' => $participation->created_at,
                'αποφασίστηκε' => $participation->decided_at,
            ];
        })->toArray();
    }

    /**
     * Αναφορά τμημάτων.
     */
    public function getDepartmentsReport(): array
    {
        $departments = Department::withCount([
            'users',
            'missions',
        ])->get();

        return $departments->map(function ($department) {
            $volunteerCount = User::where('department_id', $department->id)
                ->where('role', User::ROLE_VOLUNTEER)
                ->count();
            
            $activeMissions = Mission::where('department_id', $department->id)
                ->where('status', Mission::STATUS_OPEN)
                ->count();

            return [
                'id' => $department->id,
                'όνομα' => $department->name,
                'περιγραφή' => $department->description,
                'σύνολο_χρηστών' => $department->users_count,
                'εθελοντές' => $volunteerCount,
                'σύνολο_αποστολών' => $department->missions_count,
                'ενεργές_αποστολές' => $activeMissions,
                'ενεργό' => $department->is_active ? 'Ναι' : 'Όχι',
            ];
        })->toArray();
    }

    /**
     * Εξαγωγή αναφοράς σε μορφή.
     */
    public function exportReport(string $type, array $filters = []): array
    {
        return match ($type) {
            'missions' => $this->getMissionsReport($filters),
            'shifts' => $this->getShiftsReport($filters),
            'volunteers' => $this->getVolunteersReport($filters),
            'participations' => $this->getParticipationsReport($filters),
            'departments' => $this->getDepartmentsReport(),
            'dashboard' => $this->getDashboardStats($filters['department_id'] ?? null),
            default => [],
        };
    }
}
