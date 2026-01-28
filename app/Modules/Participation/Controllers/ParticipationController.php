<?php

namespace App\Modules\Participation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Participation\Requests\ApplyRequest;
use App\Modules\Participation\Requests\RejectRequest;
use App\Modules\Participation\Services\ParticipationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipationController extends Controller
{
    public function __construct(
        protected ParticipationService $participationService
    ) {}

    /**
     * Αίτηση συμμετοχής σε βάρδια.
     */
    public function apply(ApplyRequest $request, int $shiftId): JsonResponse
    {
        $result = $this->participationService->apply(
            $shiftId,
            $request->user()->id,
            $request->validated()
        );

        if (!$result['success']) {
            return response()->json([
                'μήνυμα' => $result['message'],
            ], 400);
        }

        return response()->json([
            'μήνυμα' => 'Η αίτηση συμμετοχής υποβλήθηκε επιτυχώς.',
            'αίτηση' => $result['participation'],
        ], 201);
    }

    /**
     * Έγκριση αίτησης.
     */
    public function approve(int $id): JsonResponse
    {
        $participation = ParticipationRequest::with('shift.mission')->findOrFail($id);
        $this->authorize('approve', $participation);

        $result = $this->participationService->approve($id);

        if (!$result['success']) {
            return response()->json([
                'μήνυμα' => $result['message'],
            ], 400);
        }

        return response()->json([
            'μήνυμα' => 'Η αίτηση εγκρίθηκε επιτυχώς.',
            'αίτηση' => $result['participation'],
        ]);
    }

    /**
     * Απόρριψη αίτησης.
     */
    public function reject(RejectRequest $request, int $id): JsonResponse
    {
        $participation = ParticipationRequest::with('shift.mission')->findOrFail($id);
        $this->authorize('reject', $participation);

        $result = $this->participationService->reject($id, $request->validated());

        if (!$result['success']) {
            return response()->json([
                'μήνυμα' => $result['message'],
            ], 400);
        }

        return response()->json([
            'μήνυμα' => 'Η αίτηση απορρίφθηκε.',
            'αίτηση' => $result['participation'],
        ]);
    }

    /**
     * Ακύρωση αίτησης.
     */
    public function cancel(int $id): JsonResponse
    {
        $participation = ParticipationRequest::with('shift.mission')->findOrFail($id);
        $this->authorize('cancel', $participation);

        $result = $this->participationService->cancel($id);

        if (!$result['success']) {
            return response()->json([
                'μήνυμα' => $result['message'],
            ], 400);
        }

        return response()->json([
            'μήνυμα' => 'Η αίτηση ακυρώθηκε.',
            'αίτηση' => $result['participation'],
        ]);
    }

    /**
     * Οι συμμετοχές μου.
     */
    public function myParticipations(Request $request): JsonResponse
    {
        $participations = $this->participationService->getByUser($request->user()->id);

        return response()->json([
            'συμμετοχές' => $participations,
        ]);
    }

    /**
     * Εκκρεμείς αιτήσεις.
     */
    public function pending(Request $request): JsonResponse
    {
        $participations = $this->participationService->getPending($request->user());

        return response()->json([
            'εκκρεμείς_αιτήσεις' => $participations,
        ]);
    }

    /**
     * Λεπτομέρειες αίτησης.
     */
    public function show(int $id): JsonResponse
    {
        $participation = $this->participationService->getById($id);

        if (!$participation) {
            return response()->json([
                'μήνυμα' => 'Η αίτηση δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'αίτηση' => $participation,
        ]);
    }
}
