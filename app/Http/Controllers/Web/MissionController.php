<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\Missions\Models\Mission;
use App\Modules\Missions\Services\MissionService;
use App\Modules\Directory\Models\Department;
use App\Models\Setting;

class MissionController extends Controller
{
    public function __construct(
        protected MissionService $missionService
    ) {
        // Εφαρμογή authorization για όλες τις actions εκτός από index και show
        $this->authorizeResource(Mission::class, 'mission');
    }

    public function index(Request $request)
    {
        $query = Mission::with('department')->withCount('shifts');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('location', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('start_datetime', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('start_datetime', '<=', $request->to_date);
        }

        $missions = $query->latest()->paginate(15);
        $departments = Department::where('is_active', true)->get();

        return view('missions.index', compact('missions', 'departments'));
    }

    public function create()
    {
        $departments = Department::where('is_active', true)->get();
        $missionTypes = Setting::getMissionTypes() ?: ['Εθελοντική', 'Υγειονομική'];
        
        // Μετατροπή σε key-value για το form
        $missionTypesFormatted = [];
        foreach ($missionTypes as $type) {
            $key = strtoupper(str_replace(['Εθελοντική', 'Υγειονομική'], ['VOLUNTEER', 'MEDICAL'], $type));
            $missionTypesFormatted[$key] = $type;
        }
        
        return view('missions.create', compact('departments', 'missionTypesFormatted'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'department_id' => 'required|exists:departments,id',
            'type' => 'required|string|in:VOLUNTEER,MEDICAL',
            'location' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'status' => 'nullable|string|in:DRAFT,OPEN',
        ]);

        $validated['created_by'] = auth()->id();
        $validated['status'] = $request->input('status', Mission::STATUS_DRAFT);

        // Χρήση του service
        $mission = $this->missionService->createWithDefaultShift($validated);

        return redirect()->route('missions.show', $mission['id'])
            ->with('success', 'Η αποστολή δημιουργήθηκε επιτυχώς.');
    }

    public function show(Mission $mission)
    {
        $mission->load(['department', 'shifts.leader', 'shifts.participations.volunteer']);
        return view('missions.show', compact('mission'));
    }

    public function edit(Mission $mission)
    {
        $departments = Department::where('is_active', true)->get();
        return view('missions.edit', compact('mission', 'departments'));
    }

    public function update(Request $request, Mission $mission)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'department_id' => 'required|exists:departments,id',
            'type' => 'required|string|in:VOLUNTEER,MEDICAL',
            'location' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'status' => 'nullable|string|in:DRAFT,OPEN,CLOSED,COMPLETED,CANCELED',
        ]);

        $this->missionService->update($mission->id, $validated);

        return redirect()->route('missions.show', $mission)
            ->with('success', 'Η αποστολή ενημερώθηκε επιτυχώς.');
    }

    public function destroy(Mission $mission)
    {
        $this->missionService->delete($mission->id);
        return redirect()->route('missions.index')
            ->with('success', 'Η αποστολή διαγράφηκε επιτυχώς.');
    }

    public function publish(Mission $mission)
    {
        $this->authorize('publish', $mission);
        $result = $this->missionService->publish($mission->id);
        
        if (!$result['success']) {
            return back()->with('error', $result['message']);
        }
        
        return back()->with('success', 'Η αποστολή δημοσιεύτηκε.');
    }

    public function activate(Mission $mission)
    {
        $this->authorize('update', $mission);
        $mission->update(['status' => Mission::STATUS_OPEN]);
        return back()->with('success', 'Η αποστολή ενεργοποιήθηκε.');
    }

    public function complete(Mission $mission)
    {
        $this->authorize('close', $mission);
        $result = $this->missionService->complete($mission->id);
        return back()->with('success', 'Η αποστολή ολοκληρώθηκε.');
    }

    public function cancel(Mission $mission)
    {
        $this->authorize('cancel', $mission);
        $result = $this->missionService->cancel($mission->id);
        return back()->with('success', 'Η αποστολή ακυρώθηκε.');
    }
}
