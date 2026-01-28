<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Missions\Models\Mission;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Directory\Models\Department;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Σελίδα αναφορών.
     */
    public function index(Request $request)
    {
        $period = $request->get('period', 'month');
        
        $startDate = match($period) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'quarter' => now()->startOfQuarter(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };
        
        $stats = [
            'total_missions' => Mission::where('created_at', '>=', $startDate)->count(),
            'completed_missions' => Mission::where('status', Mission::STATUS_COMPLETED)
                ->where('updated_at', '>=', $startDate)->count(),
            'total_participations' => ParticipationRequest::where('created_at', '>=', $startDate)->count(),
            'approved_participations' => ParticipationRequest::where('status', ParticipationRequest::STATUS_APPROVED)
                ->where('created_at', '>=', $startDate)->count(),
            'new_volunteers' => User::where('role', User::ROLE_VOLUNTEER)
                ->where('created_at', '>=', $startDate)->count(),
        ];
        
        // Top εθελοντές
        $topVolunteers = User::where('role', User::ROLE_VOLUNTEER)
            ->orderBy('total_points', 'desc')
            ->take(10)
            ->get();
            
        // Στατιστικά ανά τμήμα
        $departmentStats = Department::withCount(['users'])
            ->orderBy('users_count', 'desc')
            ->get();
        
        return view('reports.index', compact('stats', 'period', 'topVolunteers', 'departmentStats'));
    }

    /**
     * Εξαγωγή αναφοράς.
     */
    public function export(Request $request)
    {
        // TODO: Implement CSV/Excel export
        return back()->with('info', 'Η εξαγωγή θα υλοποιηθεί σύντομα.');
    }
}
