<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Missions\Models\Mission;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    /**
     * Λίστα βαρδιών.
     */
    public function index(Request $request)
    {
        $query = Shift::with(['mission']);

        if ($request->filled('mission_id')) {
            $query->where('mission_id', $request->mission_id);
        }

        $shifts = $query->orderBy('start_time', 'desc')->paginate(20);
        $missions = Mission::orderBy('title')->get();

        return view('shifts.index', compact('shifts', 'missions'));
    }

    /**
     * Εμφάνιση βάρδιας.
     */
    public function show(Shift $shift)
    {
        $shift->load(['mission', 'participations.volunteer']);
        return view('shifts.show', compact('shift'));
    }

    /**
     * Φόρμα δημιουργίας βάρδιας.
     */
    public function create(Request $request)
    {
        $mission = null;
        if ($request->filled('mission_id')) {
            $mission = Mission::findOrFail($request->mission_id);
        }
        $missions = Mission::where('status', '!=', Mission::STATUS_COMPLETED)->orderBy('title')->get();
        
        return view('shifts.create', compact('missions', 'mission'));
    }

    /**
     * Αποθήκευση νέας βάρδιας.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mission_id' => 'required|exists:missions,id',
            'title' => 'required|string|max:255',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'max_capacity' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        Shift::create($validated);

        return redirect()->route('missions.show', $validated['mission_id'])
            ->with('success', 'Η βάρδια δημιουργήθηκε επιτυχώς.');
    }

    /**
     * Φόρμα επεξεργασίας βάρδιας.
     */
    public function edit(Shift $shift)
    {
        $missions = Mission::where('status', '!=', Mission::STATUS_COMPLETED)->orderBy('title')->get();
        return view('shifts.edit', compact('shift', 'missions'));
    }

    /**
     * Ενημέρωση βάρδιας.
     */
    public function update(Request $request, Shift $shift)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'max_capacity' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $shift->update($validated);

        return redirect()->route('shifts.show', $shift)
            ->with('success', 'Η βάρδια ενημερώθηκε επιτυχώς.');
    }

    /**
     * Διαγραφή βάρδιας.
     */
    public function destroy(Shift $shift)
    {
        $missionId = $shift->mission_id;
        $shift->delete();

        return redirect()->route('missions.show', $missionId)
            ->with('success', 'Η βάρδια διαγράφηκε επιτυχώς.');
    }
}
