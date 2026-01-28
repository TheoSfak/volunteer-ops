<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Missions\Models\Mission;
use App\Modules\Shifts\Models\Shift;

class ParticipationController extends Controller
{
    public function index(Request $request)
    {
        $query = ParticipationRequest::with(['volunteer', 'shift.mission']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('mission_id')) {
            $query->whereHas('shift', function ($q) use ($request) {
                $q->where('mission_id', $request->mission_id);
            });
        }

        if ($request->filled('from_date')) {
            // Μετατροπή από dd/mm/yyyy σε Y-m-d
            $fromDate = \Carbon\Carbon::createFromFormat('d/m/Y', $request->from_date)->format('Y-m-d');
            $query->whereDate('created_at', '>=', $fromDate);
        }

        if ($request->filled('to_date')) {
            // Μετατροπή από dd/mm/yyyy σε Y-m-d
            $toDate = \Carbon\Carbon::createFromFormat('d/m/Y', $request->to_date)->format('Y-m-d');
            $query->whereDate('created_at', '<=', $toDate);
        }

        $participations = $query->latest()->paginate(20);
        $missions = Mission::whereIn('status', ['published', 'active'])->get();
        
        $stats = [
            'pending' => ParticipationRequest::where('status', 'PENDING')->count(),
            'approved' => ParticipationRequest::where('status', 'APPROVED')->count(),
            'rejected' => ParticipationRequest::where('status', 'REJECTED')->count(),
            'cancelled' => ParticipationRequest::where('status', 'CANCELED')->count(),
        ];

        return view('participations.index', compact('participations', 'missions', 'stats'));
    }

    /**
     * Δήλωση συμμετοχής σε βάρδια.
     */
    public function apply(Request $request, Shift $shift)
    {
        $user = auth()->user();
        
        // Έλεγχος αν υπάρχει ήδη αίτηση
        $existingRequest = ParticipationRequest::where('shift_id', $shift->id)
            ->where('volunteer_id', $user->id)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->first();
            
        if ($existingRequest) {
            return back()->with('error', 'Έχετε ήδη υποβάλει αίτηση για αυτή τη βάρδια.');
        }
        
        // Έλεγχος αν η βάρδια είναι πλήρης
        $approvedCount = ParticipationRequest::where('shift_id', $shift->id)
            ->where('status', 'APPROVED')
            ->count();
            
        if ($shift->max_capacity && $approvedCount >= $shift->max_capacity) {
            return back()->with('error', 'Η βάρδια είναι πλήρης.');
        }
        
        // Έλεγχος αν η βάρδια έχει περάσει
        if ($shift->start_time && $shift->start_time < now()) {
            return back()->with('error', 'Αυτή η βάρδια έχει ήδη ξεκινήσει.');
        }
        
        // Δημιουργία αίτησης
        ParticipationRequest::create([
            'shift_id' => $shift->id,
            'volunteer_id' => $user->id,
            'status' => 'PENDING',
            'notes' => $request->input('notes'),
        ]);
        
        return back()->with('success', 'Η αίτηση συμμετοχής υποβλήθηκε επιτυχώς!');
    }
    
    /**
     * Ακύρωση αίτησης συμμετοχής.
     */
    public function cancel(ParticipationRequest $participation)
    {
        $user = auth()->user();
        
        // Μόνο ο ίδιος ο εθελοντής μπορεί να ακυρώσει
        if ($participation->volunteer_id !== $user->id && !$user->isAdmin()) {
            return back()->with('error', 'Δεν έχετε δικαίωμα να ακυρώσετε αυτή την αίτηση.');
        }
        
        $participation->update([
            'status' => 'CANCELED',
        ]);
        
        return back()->with('success', 'Η αίτηση ακυρώθηκε.');
    }
    
    /**
     * Δήλωση συμμετοχής σε αποστολή (χωρίς βάρδια).
     * Δημιουργεί αυτόματα μια default βάρδια αν δεν υπάρχει.
     */
    public function applyToMission(Request $request, Mission $mission)
    {
        $user = auth()->user();
        
        // Έλεγχος αν η αποστολή δέχεται συμμετοχές
        if (!in_array($mission->status, ['published', 'active'])) {
            return back()->with('error', 'Η αποστολή δεν δέχεται συμμετοχές αυτή τη στιγμή.');
        }
        
        // Αν δεν υπάρχει βάρδια, δημιούργησε μία default
        $shift = $mission->shifts()->first();
        
        if (!$shift) {
            $shift = Shift::create([
                'mission_id' => $mission->id,
                'title' => 'Γενική Βάρδια',
                'description' => 'Αυτόματη βάρδια για την αποστολή',
                'start_time' => $mission->start_date,
                'end_time' => $mission->end_date,
                'max_capacity' => $mission->max_capacity ?? null,
            ]);
        }
        
        // Έλεγχος αν υπάρχει ήδη αίτηση σε οποιαδήποτε βάρδια της αποστολής
        $existingRequest = ParticipationRequest::whereHas('shift', function ($q) use ($mission) {
                $q->where('mission_id', $mission->id);
            })
            ->where('volunteer_id', $user->id)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->first();
            
        if ($existingRequest) {
            return back()->with('error', 'Έχετε ήδη υποβάλει αίτηση για αυτή την αποστολή.');
        }
        
        // Δημιουργία αίτησης
        ParticipationRequest::create([
            'shift_id' => $shift->id,
            'volunteer_id' => $user->id,
            'status' => 'PENDING',
            'notes' => $request->input('notes'),
        ]);
        
        return back()->with('success', 'Η αίτηση συμμετοχής υποβλήθηκε επιτυχώς!');
    }

    public function approve(ParticipationRequest $participation)
    {
        $participation->update([
            'status' => 'APPROVED',
            'responded_at' => now(),
        ]);

        return back()->with('success', 'Η αίτηση εγκρίθηκε.');
    }

    public function reject(ParticipationRequest $participation)
    {
        $participation->update([
            'status' => 'REJECTED',
            'responded_at' => now(),
        ]);

        return back()->with('success', 'Η αίτηση απορρίφθηκε.');
    }

    public function checkin(ParticipationRequest $participation)
    {
        $participation->update(['check_in_at' => now()]);
        return back()->with('success', 'Check-in επιτυχές.');
    }

    public function checkout(ParticipationRequest $participation)
    {
        $participation->update(['check_out_at' => now()]);
        return back()->with('success', 'Check-out επιτυχές.');
    }
}
