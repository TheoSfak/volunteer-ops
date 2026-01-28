<?php

namespace App\Modules\Missions\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Missions\Models\Mission;
use App\Modules\Missions\Requests\StoreMissionRequest;
use App\Modules\Missions\Requests\UpdateMissionRequest;
use App\Modules\Missions\Services\MissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MissionController extends Controller
{
    public function __construct(
        protected MissionService $missionService
    ) {}

    /**
     * Λίστα αποστολών.
     */
    public function index(Request $request): JsonResponse
    {
        $missions = $this->missionService->getAll($request->all());

        return response()->json([
            'αποστολές' => $missions['data'],
            'σύνολο' => $missions['total'],
            'σελίδα' => $missions['current_page'],
            'ανά_σελίδα' => $missions['per_page'],
        ]);
    }

    /**
     * Δημιουργία νέας αποστολής.
     */
    public function store(StoreMissionRequest $request): JsonResponse
    {
        $this->authorize('create', Mission::class);

        $mission = $this->missionService->create($request->validated());

        return response()->json([
            'μήνυμα' => 'Η αποστολή δημιουργήθηκε επιτυχώς.',
            'αποστολή' => $mission,
        ], 201);
    }

    /**
     * Προβολή αποστολής.
     */
    public function show(int $id): JsonResponse
    {
        $mission = $this->missionService->getById($id);

        if (!$mission) {
            return response()->json([
                'μήνυμα' => 'Η αποστολή δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'αποστολή' => $mission,
        ]);
    }

    /**
     * Ενημέρωση αποστολής.
     */
    public function update(UpdateMissionRequest $request, int $id): JsonResponse
    {
        $mission = Mission::findOrFail($id);
        $this->authorize('update', $mission);

        $updated = $this->missionService->update($id, $request->validated());

        return response()->json([
            'μήνυμα' => 'Η αποστολή ενημερώθηκε επιτυχώς.',
            'αποστολή' => $updated,
        ]);
    }

    /**
     * Δημοσίευση αποστολής.
     */
    public function publish(int $id): JsonResponse
    {
        $mission = Mission::findOrFail($id);
        $this->authorize('publish', $mission);

        $result = $this->missionService->publish($id);

        if (!$result['success']) {
            return response()->json([
                'μήνυμα' => $result['message'],
            ], 400);
        }

        return response()->json([
            'μήνυμα' => 'Η αποστολή δημοσιεύτηκε επιτυχώς.',
            'αποστολή' => $result['mission'],
        ]);
    }

    /**
     * Κλείσιμο αποστολής.
     */
    public function close(int $id): JsonResponse
    {
        $mission = Mission::findOrFail($id);
        $this->authorize('close', $mission);

        $result = $this->missionService->close($id);

        if (!$result['success']) {
            return response()->json([
                'μήνυμα' => $result['message'],
            ], 400);
        }

        return response()->json([
            'μήνυμα' => 'Η αποστολή έκλεισε επιτυχώς.',
            'αποστολή' => $result['mission'],
        ]);
    }

    /**
     * Ακύρωση αποστολής.
     */
    public function cancel(int $id): JsonResponse
    {
        $mission = Mission::findOrFail($id);
        $this->authorize('cancel', $mission);

        $result = $this->missionService->cancel($id);

        if (!$result['success']) {
            return response()->json([
                'μήνυμα' => $result['message'],
            ], 400);
        }

        return response()->json([
            'μήνυμα' => 'Η αποστολή ακυρώθηκε επιτυχώς.',
            'αποστολή' => $result['mission'],
        ]);
    }

    /**
     * Στατιστικά αποστολής.
     */
    public function stats(int $id): JsonResponse
    {
        $stats = $this->missionService->getStats($id);

        if (!$stats) {
            return response()->json([
                'μήνυμα' => 'Η αποστολή δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'στατιστικά' => $stats,
        ]);
    }
}
