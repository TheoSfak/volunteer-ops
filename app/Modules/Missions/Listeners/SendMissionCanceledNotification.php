<?php

namespace App\Modules\Missions\Listeners;

use App\Modules\Missions\Events\MissionCanceled;
use App\Modules\Participation\Models\ParticipationRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendMissionCanceledNotification implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(MissionCanceled $event): void
    {
        $mission = $event->mission;
        $reason = $event->reason;
        
        // Λήψη όλων των εθελοντών που είχαν εγκεκριμένη συμμετοχή
        $volunteers = collect();
        
        foreach ($mission->shifts as $shift) {
            $approvedParticipations = $shift->participations()
                ->whereIn('status', [
                    ParticipationRequest::STATUS_APPROVED,
                    ParticipationRequest::STATUS_PENDING
                ])
                ->with('volunteer')
                ->get();
            
            foreach ($approvedParticipations as $participation) {
                if ($participation->volunteer && !$volunteers->contains('id', $participation->volunteer->id)) {
                    $volunteers->push($participation->volunteer);
                }
            }
        }

        foreach ($volunteers as $volunteer) {
            try {
                Mail::send('emails.mission-canceled', [
                    'volunteer' => $volunteer,
                    'mission' => $mission,
                    'reason' => $reason,
                ], function ($message) use ($volunteer, $mission) {
                    $message->to($volunteer->email, $volunteer->name)
                            ->subject("Ακύρωση Αποστολής: {$mission->title}");
                });
            } catch (\Exception $e) {
                Log::error("Failed to send mission canceled email to {$volunteer->email}: " . $e->getMessage());
            }
        }

        Log::info("Mission canceled notification sent for mission {$mission->id} to {$volunteers->count()} volunteers.");
    }
}
