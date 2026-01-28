<?php

namespace App\Modules\Volunteers\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Volunteers\Models\VolunteerProfile;
use App\Modules\Volunteers\Requests\UpdateVolunteerRequest;
use App\Modules\Volunteers\Services\VolunteerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VolunteerController extends Controller
{
    public function __construct(
        protected VolunteerService $volunteerService
    ) {}

    /**
     * Λίστα όλων των εθελοντών.
     */
    public function index(Request $request): JsonResponse
    {
        $volunteers = $this->volunteerService->getAll($request->all());

        return response()->json([
            'εθελοντές' => $volunteers['data'],
            'σύνολο' => $volunteers['total'],
            'σελίδα' => $volunteers['current_page'],
            'ανά_σελίδα' => $volunteers['per_page'],
        ]);
    }

    /**
     * Προβολή συγκεκριμένου εθελοντή.
     */
    public function show(int $id): JsonResponse
    {
        $volunteer = $this->volunteerService->getById($id);

        if (!$volunteer) {
            return response()->json([
                'μήνυμα' => 'Ο εθελοντής δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'εθελοντής' => $volunteer,
        ]);
    }

    /**
     * Ενημέρωση εθελοντή.
     */
    public function update(UpdateVolunteerRequest $request, int $id): JsonResponse
    {
        $profile = VolunteerProfile::where('user_id', $id)->firstOrFail();
        $this->authorize('update', $profile);

        $volunteer = $this->volunteerService->update($id, $request->validated());

        return response()->json([
            'μήνυμα' => 'Τα στοιχεία του εθελοντή ενημερώθηκαν επιτυχώς.',
            'εθελοντής' => $volunteer,
        ]);
    }

    /**
     * Αναζήτηση εθελοντών.
     */
    public function search(Request $request): JsonResponse
    {
        $volunteers = $this->volunteerService->search($request->get('q', ''));

        return response()->json([
            'εθελοντές' => $volunteers,
        ]);
    }

    /**
     * Στατιστικά εθελοντή.
     */
    public function stats(int $id): JsonResponse
    {
        $stats = $this->volunteerService->getStats($id);

        if (!$stats) {
            return response()->json([
                'μήνυμα' => 'Ο εθελοντής δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'στατιστικά' => $stats,
        ]);
    }

    /**
     * Ιστορικό συμμετοχών εθελοντή.
     */
    public function history(int $id): JsonResponse
    {
        $history = $this->volunteerService->getHistory($id);

        if ($history === null) {
            return response()->json([
                'μήνυμα' => 'Ο εθελοντής δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'ιστορικό' => $history,
        ]);
    }
}
