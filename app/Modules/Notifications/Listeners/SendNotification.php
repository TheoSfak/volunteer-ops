<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Missions\Events\MissionPublished;
use App\Modules\Shifts\Events\ShiftFull;
use App\Modules\Participation\Events\ParticipationRequested;
use App\Modules\Participation\Events\ParticipationApproved;
use App\Modules\Participation\Events\ParticipationRejected;
use App\Modules\Notifications\Services\NotificationService;

class SendNotification
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Χειρισμός event και αποστολή ειδοποίησης.
     */
    public function handle(object $event): void
    {
        match (get_class($event)) {
            MissionPublished::class => $this->handleMissionPublished($event),
            ShiftFull::class => $this->handleShiftFull($event),
            ParticipationRequested::class => $this->handleParticipationRequested($event),
            ParticipationApproved::class => $this->handleParticipationApproved($event),
            ParticipationRejected::class => $this->handleParticipationRejected($event),
            default => null,
        };
    }

    /**
     * Ειδοποίηση για δημοσίευση αποστολής.
     */
    protected function handleMissionPublished(MissionPublished $event): void
    {
        $this->notificationService->notifyDepartmentUsers(
            $event->mission->department_id,
            'Νέα Αποστολή',
            "Δημοσιεύτηκε νέα αποστολή: {$event->mission->title}",
            [
                'type' => 'mission_published',
                'mission_id' => $event->mission->id,
            ]
        );
    }

    /**
     * Ειδοποίηση για πλήρη βάρδια.
     */
    protected function handleShiftFull(ShiftFull $event): void
    {
        // Ειδοποίηση στον αρχηγό βάρδιας
        if ($event->shift->leader_user_id) {
            $this->notificationService->notifyUser(
                $event->shift->leader_user_id,
                'Πλήρης Βάρδια',
                "Η βάρδια '{$event->shift->title}' συμπληρώθηκε.",
                [
                    'type' => 'shift_full',
                    'shift_id' => $event->shift->id,
                ]
            );
        }
    }

    /**
     * Ειδοποίηση για νέα αίτηση συμμετοχής.
     */
    protected function handleParticipationRequested(ParticipationRequested $event): void
    {
        $shift = $event->participation->shift;
        
        // Ειδοποίηση στον αρχηγό βάρδιας
        if ($shift->leader_user_id) {
            $this->notificationService->notifyUser(
                $shift->leader_user_id,
                'Νέα Αίτηση Συμμετοχής',
                "Νέα αίτηση για τη βάρδια '{$shift->title}'.",
                [
                    'type' => 'participation_requested',
                    'participation_id' => $event->participation->id,
                ]
            );
        }
    }

    /**
     * Ειδοποίηση για έγκριση συμμετοχής.
     */
    protected function handleParticipationApproved(ParticipationApproved $event): void
    {
        $this->notificationService->notifyUser(
            $event->participation->user_id,
            'Αίτηση Εγκρίθηκε',
            "Η αίτησή σας για τη βάρδια '{$event->participation->shift->title}' εγκρίθηκε!",
            [
                'type' => 'participation_approved',
                'participation_id' => $event->participation->id,
            ]
        );
    }

    /**
     * Ειδοποίηση για απόρριψη συμμετοχής.
     */
    protected function handleParticipationRejected(ParticipationRejected $event): void
    {
        $this->notificationService->notifyUser(
            $event->participation->user_id,
            'Αίτηση Απορρίφθηκε',
            "Η αίτησή σας για τη βάρδια '{$event->participation->shift->title}' απορρίφθηκε.",
            [
                'type' => 'participation_rejected',
                'participation_id' => $event->participation->id,
            ]
        );
    }
}
