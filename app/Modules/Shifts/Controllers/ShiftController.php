<?php

namespace App\Modules\Shifts\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Missions\Models\Mission;
use App\Modules\Shifts\Requests\StoreShiftRequest;
use App\Modules\Shifts\Requests\UpdateShiftRequest;
use App\Modules\Shifts\Services\ShiftService;
use Illuminate\Http\JsonResponse;

class ShiftController extends Controller
{
    public function __construct(
        protected ShiftService $shiftService
    ) {}

    /**
     * Λίστα βαρδιών αποστολής.
     */
    public function index(int $missionId): JsonResponse
    {
        $shifts = $this->shiftService->getByMission($missionId);

        return response()->json([
            'βάρδιες' => $shifts,
        ]);
    }

    /**
     * Δημιουργία νέας βάρδιας.
     */
    public function store(StoreShiftRequest $request, int $missionId): JsonResponse
    {
        $mission = Mission::findOrFail($missionId);
        $this->authorize('update', $mission);

        $shift = $this->shiftService->create($missionId, $request->validated());

        return response()->json([
            'μήνυμα' => 'Η βάρδια δημιουργήθηκε επιτυχώς.',
            'βάρδια' => $shift,
        ], 201);
    }

    /**
     * Προβολή βάρδιας.
     */
    public function show(int $id): JsonResponse
    {
        $shift = $this->shiftService->getById($id);

        if (!$shift) {
            return response()->json([
                'μήνυμα' => 'Η βάρδια δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'βάρδια' => $shift,
        ]);
    }

    /**
     * Ενημέρωση βάρδιας.
     */
    public function update(UpdateShiftRequest $request, int $id): JsonResponse
    {
        $shift = Shift::with('mission')->findOrFail($id);
        $this->authorize('update', $shift);

        $updated = $this->shiftService->update($id, $request->validated());

        return response()->json([
            'μήνυμα' => 'Η βάρδια ενημερώθηκε επιτυχώς.',
            'βάρδια' => $updated,
        ]);
    }

    /**
     * Κλείδωμα βάρδιας.
     */
    public function lock(int $id): JsonResponse
    {
        $shift = Shift::with('mission')->findOrFail($id);
        $this->authorize('lock', $shift);

        $result = $this->shiftService->lock($id);

        if (!$result['success']) {
            return response()->json([
                'μήνυμα' => $result['message'],
            ], 400);
        }

        return response()->json([
            'μήνυμα' => 'Η βάρδια κλειδώθηκε επιτυχώς.',
            'βάρδια' => $result['shift'],
        ]);
    }

    /**
     * Λίστα εθελοντών βάρδιας.
     */
    public function volunteers(int $id): JsonResponse
    {
        $volunteers = $this->shiftService->getVolunteers($id);

        if ($volunteers === null) {
            return response()->json([
                'μήνυμα' => 'Η βάρδια δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'εθελοντές' => $volunteers,
        ]);
    }
}
