<?php

namespace App\Modules\Volunteers\Services;

use App\Models\User;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Missions\Models\Mission;
use Illuminate\Support\Collection;

class VolunteerHoursService
{
    /**
     * Υπολογισμός ωρών ανά τύπο αποστολής για έναν εθελοντή.
     */
    public function getHoursByType(User $user): array
    {
        $hours = [
            'volunteer' => 0.0,  // Εθελοντικές
            'medical' => 0.0,    // Υγειονομικές
            'total' => 0.0,      // Σύνολο
        ];
        
        $approvedParticipations = $user->participationRequests()
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift.mission')
            ->get();
        
        foreach ($approvedParticipations as $participation) {
            $shift = $participation->shift;
            if (!$shift || !$shift->start_time || !$shift->end_time) {
                continue;
            }

            $minutes = $shift->end_time->diffInMinutes($shift->start_time);
            $h = round($minutes / 60, 1);
            
            $missionType = $shift->mission->type ?? Mission::TYPE_VOLUNTEER;
            
            if ($missionType === Mission::TYPE_MEDICAL) {
                $hours['medical'] += $h;
            } else {
                $hours['volunteer'] += $h;
            }
            $hours['total'] += $h;
        }
        
        return $hours;
    }

    /**
     * Λήψη ιστορικού συμμετοχών με ώρες.
     */
    public function getParticipationHistory(User $user): Collection
    {
        return $user->participationRequests()
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with(['shift.mission'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($participation) {
                $shift = $participation->shift;
                $mission = $shift->mission ?? null;
                
                $hours = 0;
                if ($shift && $shift->start_time && $shift->end_time) {
                    $hours = round($shift->end_time->diffInMinutes($shift->start_time) / 60, 1);
                }
                
                return [
                    'id' => $participation->id,
                    'mission_id' => $mission->id ?? null,
                    'mission' => $mission->title ?? 'Άγνωστη αποστολή',
                    'mission_type' => $mission->type ?? Mission::TYPE_VOLUNTEER,
                    'mission_type_label' => $mission->type_label ?? 'Εθελοντική',
                    'shift_id' => $shift->id ?? null,
                    'shift' => $shift->title ?? 'Βάρδια',
                    'date' => $shift->start_time?->format('d/m/Y') ?? '-',
                    'start_time' => $shift->start_time?->format('H:i') ?? '-',
                    'end_time' => $shift->end_time?->format('H:i') ?? '-',
                    'hours' => $hours,
                    'location' => $mission->location ?? '',
                    'participated_at' => $participation->created_at,
                ];
            });
    }

    /**
     * Σύνοψη στατιστικών εθελοντή.
     */
    public function getVolunteerStats(User $user): array
    {
        $hours = $this->getHoursByType($user);
        $history = $this->getParticipationHistory($user);

        return [
            'volunteer_hours' => $hours['volunteer'],
            'medical_hours' => $hours['medical'],
            'total_hours' => $hours['total'],
            'total_participations' => $history->count(),
            'missions_count' => $history->pluck('mission_id')->unique()->filter()->count(),
        ];
    }
}
