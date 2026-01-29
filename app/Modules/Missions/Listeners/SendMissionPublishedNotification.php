<?php

namespace App\Modules\Missions\Listeners;

use App\Models\User;
use App\Modules\Missions\Events\MissionPublished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendMissionPublishedNotification implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(MissionPublished $event): void
    {
        $mission = $event->mission;
        
        // Λήψη όλων των ενεργών εθελοντών
        $volunteers = User::where('role', User::ROLE_VOLUNTEER)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        foreach ($volunteers as $volunteer) {
            try {
                Mail::send('emails.mission-published', [
                    'volunteer' => $volunteer,
                    'mission' => $mission,
                ], function ($message) use ($volunteer, $mission) {
                    $message->to($volunteer->email, $volunteer->name)
                            ->subject("Νέα Αποστολή: {$mission->title}");
                });
            } catch (\Exception $e) {
                Log::error("Failed to send mission published email to {$volunteer->email}: " . $e->getMessage());
            }
        }

        Log::info("Mission published notification sent for mission {$mission->id} to {$volunteers->count()} volunteers.");
    }
}
