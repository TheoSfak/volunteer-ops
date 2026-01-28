<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService
    ) {}

    /**
     * GET /reports/dashboard
     * Γενικά στατιστικά dashboard.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $departmentId = null;

        // Αν ο χρήστης είναι DEPARTMENT_ADMIN, βλέπει μόνο το τμήμα του
        if ($user->role === \App\Models\User::ROLE_DEPARTMENT_ADMIN) {
            $departmentId = $user->department_id;
        }

        // Αν ο χρήστης ζητήσει συγκεκριμένο τμήμα
        if ($request->has('department_id') && $user->role === \App\Models\User::ROLE_SYSTEM_ADMIN) {
            $departmentId = $request->input('department_id');
        }

        $stats = $this->reportService->getDashboardStats($departmentId);

        return response()->json([
            'μήνυμα' => 'Στατιστικά Dashboard',
            'δεδομένα' => $stats,
        ]);
    }

    /**
     * GET /reports/missions
     * Αναφορά αποστολών.
     */
    public function missions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Modules\Missions\Models\Mission::class);

        $filters = $request->only([
            'department_id',
            'status',
            'type',
            'date_from',
            'date_to',
        ]);

        $data = $this->reportService->getMissionsReport($filters);

        return response()->json([
            'μήνυμα' => 'Αναφορά αποστολών',
            'σύνολο' => count($data),
            'δεδομένα' => $data,
        ]);
    }

    /**
     * GET /reports/shifts
     * Αναφορά βαρδιών.
     */
    public function shifts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Modules\Shifts\Models\Shift::class);

        $filters = $request->only([
            'mission_id',
            'status',
            'date_from',
            'date_to',
        ]);

        $data = $this->reportService->getShiftsReport($filters);

        return response()->json([
            'μήνυμα' => 'Αναφορά βαρδιών',
            'σύνολο' => count($data),
            'δεδομένα' => $data,
        ]);
    }

    /**
     * GET /reports/volunteers
     * Αναφορά εθελοντών.
     */
    public function volunteers(Request $request): JsonResponse
    {
        $user = $request->user();

        // Μόνο admins μπορούν να δουν αυτή την αναφορά
        if (!in_array($user->role, [
            \App\Models\User::ROLE_SYSTEM_ADMIN,
            \App\Models\User::ROLE_DEPARTMENT_ADMIN,
        ])) {
            return response()->json([
                'μήνυμα' => 'Δεν έχετε δικαίωμα πρόσβασης.',
            ], 403);
        }

        $filters = $request->only([
            'department_id',
            'is_active',
        ]);

        // DEPARTMENT_ADMIN βλέπει μόνο το τμήμα του
        if ($user->role === \App\Models\User::ROLE_DEPARTMENT_ADMIN) {
            $filters['department_id'] = $user->department_id;
        }

        $data = $this->reportService->getVolunteersReport($filters);

        return response()->json([
            'μήνυμα' => 'Αναφορά εθελοντών',
            'σύνολο' => count($data),
            'δεδομένα' => $data,
        ]);
    }

    /**
     * GET /reports/participations
     * Αναφορά συμμετοχών.
     */
    public function participations(Request $request): JsonResponse
    {
        $user = $request->user();

        // Μόνο admins μπορούν να δουν πλήρη αναφορά
        if (!in_array($user->role, [
            \App\Models\User::ROLE_SYSTEM_ADMIN,
            \App\Models\User::ROLE_DEPARTMENT_ADMIN,
            \App\Models\User::ROLE_SHIFT_LEADER,
        ])) {
            return response()->json([
                'μήνυμα' => 'Δεν έχετε δικαίωμα πρόσβασης.',
            ], 403);
        }

        $filters = $request->only([
            'status',
            'volunteer_id',
            'date_from',
            'date_to',
        ]);

        $data = $this->reportService->getParticipationsReport($filters);

        return response()->json([
            'μήνυμα' => 'Αναφορά συμμετοχών',
            'σύνολο' => count($data),
            'δεδομένα' => $data,
        ]);
    }

    /**
     * GET /reports/departments
     * Αναφορά τμημάτων.
     */
    public function departments(Request $request): JsonResponse
    {
        // Μόνο SYSTEM_ADMIN
        if ($request->user()->role !== \App\Models\User::ROLE_SYSTEM_ADMIN) {
            return response()->json([
                'μήνυμα' => 'Δεν έχετε δικαίωμα πρόσβασης.',
            ], 403);
        }

        $data = $this->reportService->getDepartmentsReport();

        return response()->json([
            'μήνυμα' => 'Αναφορά τμημάτων',
            'σύνολο' => count($data),
            'δεδομένα' => $data,
        ]);
    }

    /**
     * GET /reports/export/{type}
     * Εξαγωγή αναφοράς.
     */
    public function export(Request $request, string $type): JsonResponse
    {
        $validTypes = ['missions', 'shifts', 'volunteers', 'participations', 'departments', 'dashboard'];
        
        if (!in_array($type, $validTypes)) {
            return response()->json([
                'μήνυμα' => 'Μη έγκυρος τύπος αναφοράς.',
                'διαθέσιμοι_τύποι' => $validTypes,
            ], 400);
        }

        $filters = $request->all();
        $data = $this->reportService->exportReport($type, $filters);

        return response()->json([
            'μήνυμα' => 'Εξαγωγή αναφοράς',
            'τύπος' => $type,
            'ημερομηνία_εξαγωγής' => now()->toIso8601String(),
            'σύνολο_εγγραφών' => is_array($data) && !isset($data['αποστολές']) ? count($data) : null,
            'δεδομένα' => $data,
        ]);
    }
}
