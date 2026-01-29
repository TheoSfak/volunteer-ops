<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Missions\Models\Mission;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller για τη διαχείριση παρουσιών εθελοντών σε αποστολές.
 * Χρησιμοποιείται όταν η αποστολή είναι σε κατάσταση CLOSED.
 */
class AttendanceController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {
        // Μόνο admins
        $this->middleware(function ($request, $next) {
            if (!auth()->user()->isAdmin()) {
                abort(403, 'Δεν έχετε δικαίωμα πρόσβασης.');
            }
            return $next($request);
        });
    }

    /**
     * Σελίδα διαχείρισης παρουσιών για μια αποστολή.
     */
    public function manage(Mission $mission)
    {
        // Έλεγχος ότι η αποστολή είναι CLOSED
        if (!$mission->isClosed()) {
            return redirect()->route('missions.show', $mission)
                ->with('error', 'Η διαχείριση παρουσιών είναι διαθέσιμη μόνο για κλειστές αποστολές.');
        }

        $mission->load([
            'shifts.participations' => function ($query) {
                $query->where('status', ParticipationRequest::STATUS_APPROVED)
                      ->with('volunteer');
            },
            'department'
        ]);

        return view('attendance.manage', compact('mission'));
    }

    /**
     * Ενημέρωση παρουσίας ενός εθελοντή.
     */
    public function update(Request $request, ParticipationRequest $participation)
    {
        $mission = $participation->shift->mission;

        // Έλεγχος ότι η αποστολή είναι CLOSED
        if (!$mission->isClosed()) {
            return back()->with('error', 'Η επεξεργασία παρουσιών είναι διαθέσιμη μόνο για κλειστές αποστολές.');
        }

        $validated = $request->validate([
            'attended' => 'required|boolean',
            'hours_type' => 'required|in:shift,custom_time,custom_hours',
            'actual_hours' => 'required_if:hours_type,custom_hours|nullable|numeric|min:0|max:24',
            'actual_start_time' => 'required_if:hours_type,custom_time|nullable|date_format:H:i',
            'actual_end_time' => 'required_if:hours_type,custom_time|nullable|date_format:H:i',
            'admin_notes' => 'nullable|string|max:500',
        ], [
            'attended.required' => 'Παρακαλώ επιλέξτε αν ήρθε ο εθελοντής.',
            'actual_hours.required_if' => 'Εισάγετε τις ώρες.',
            'actual_hours.numeric' => 'Οι ώρες πρέπει να είναι αριθμός.',
            'actual_start_time.required_if' => 'Εισάγετε ώρα έναρξης.',
            'actual_end_time.required_if' => 'Εισάγετε ώρα λήξης.',
        ]);

        $before = $participation->toArray();

        $updateData = [
            'attended' => $validated['attended'],
            'admin_notes' => $validated['admin_notes'] ?? null,
            'attendance_confirmed_at' => now(),
            'attendance_confirmed_by' => auth()->id(),
        ];

        // Αν είναι no-show, μηδενίζουμε τις ώρες
        if (!$validated['attended']) {
            $updateData['actual_hours'] = 0;
            $updateData['actual_start_time'] = null;
            $updateData['actual_end_time'] = null;
        } else {
            // Ανάλογα με τον τύπο ωρών
            switch ($validated['hours_type']) {
                case 'shift':
                    // Χρήση ωρών βάρδιας
                    $updateData['actual_hours'] = null;
                    $updateData['actual_start_time'] = null;
                    $updateData['actual_end_time'] = null;
                    break;
                case 'custom_time':
                    // Χρήση custom ωρών έναρξης/λήξης
                    $updateData['actual_hours'] = null;
                    $updateData['actual_start_time'] = $validated['actual_start_time'];
                    $updateData['actual_end_time'] = $validated['actual_end_time'];
                    break;
                case 'custom_hours':
                    // Χρήση απευθείας ωρών
                    $updateData['actual_hours'] = $validated['actual_hours'];
                    $updateData['actual_start_time'] = null;
                    $updateData['actual_end_time'] = null;
                    break;
            }
        }

        $participation->update($updateData);

        $this->auditService->log(
            actor: auth()->user(),
            action: 'ΕΝΗΜΕΡΩΣΗ_ΠΑΡΟΥΣΙΑΣ',
            entityType: 'ParticipationRequest',
            entityId: $participation->id,
            before: $before,
            after: $participation->fresh()->toArray()
        );

        return back()->with('success', 'Η παρουσία του εθελοντή ενημερώθηκε.');
    }

    /**
     * Μαζική ενημέρωση παρουσιών.
     */
    public function bulkUpdate(Request $request, Mission $mission)
    {
        // Έλεγχος ότι η αποστολή είναι CLOSED
        if (!$mission->isClosed()) {
            return back()->with('error', 'Η επεξεργασία παρουσιών είναι διαθέσιμη μόνο για κλειστές αποστολές.');
        }

        $validated = $request->validate([
            'participations' => 'required|array',
            'participations.*.id' => 'required|exists:participation_requests,id',
            'participations.*.attended' => 'required|boolean',
            'participations.*.actual_hours' => 'nullable|numeric|min:0|max:24',
            'participations.*.admin_notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['participations'] as $data) {
                $participation = ParticipationRequest::find($data['id']);
                
                // Βεβαιωνόμαστε ότι ανήκει σε αυτή την αποστολή
                if ($participation->shift->mission_id !== $mission->id) {
                    continue;
                }

                $updateData = [
                    'attended' => $data['attended'],
                    'admin_notes' => $data['admin_notes'] ?? null,
                    'attendance_confirmed_at' => now(),
                    'attendance_confirmed_by' => auth()->id(),
                ];

                if (!$data['attended']) {
                    $updateData['actual_hours'] = 0;
                } elseif (isset($data['actual_hours'])) {
                    $updateData['actual_hours'] = $data['actual_hours'];
                }

                $participation->update($updateData);
            }

            DB::commit();

            $this->auditService->log(
                actor: auth()->user(),
                action: 'ΜΑΖΙΚΗ_ΕΝΗΜΕΡΩΣΗ_ΠΑΡΟΥΣΙΩΝ',
                entityType: 'Mission',
                entityId: $mission->id,
                after: ['count' => count($validated['participations'])]
            );

            return redirect()->route('missions.show', $mission)
                ->with('success', 'Οι παρουσίες ενημερώθηκαν επιτυχώς.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Σφάλμα κατά την ενημέρωση: ' . $e->getMessage());
        }
    }

    /**
     * Γρήγορο mark all as attended.
     */
    public function markAllAttended(Mission $mission)
    {
        if (!$mission->isClosed()) {
            return back()->with('error', 'Η αποστολή πρέπει να είναι κλειστή.');
        }

        $count = 0;
        foreach ($mission->shifts as $shift) {
            $updated = $shift->participations()
                ->where('status', ParticipationRequest::STATUS_APPROVED)
                ->whereNull('attendance_confirmed_at')
                ->update([
                    'attended' => true,
                    'attendance_confirmed_at' => now(),
                    'attendance_confirmed_by' => auth()->id(),
                ]);
            $count += $updated;
        }

        return back()->with('success', "Επιβεβαιώθηκε η παρουσία για {$count} εθελοντές.");
    }
}
