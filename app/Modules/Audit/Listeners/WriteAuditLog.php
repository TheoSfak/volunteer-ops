<?php

namespace App\Modules\Audit\Listeners;

use App\Modules\Audit\Services\AuditService;
use Illuminate\Support\Facades\Auth;

class WriteAuditLog
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Χειρισμός event και καταγραφή στο audit log.
     */
    public function handle(object $event): void
    {
        $entityType = $this->getEntityType($event);
        $entityId = $this->getEntityId($event);
        $action = $this->getAction($event);

        $this->auditService->log(
            actor: Auth::user(),
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            after: $this->getEventData($event)
        );
    }

    /**
     * Εξαγωγή τύπου οντότητας από event.
     */
    protected function getEntityType(object $event): string
    {
        $className = class_basename($event);
        
        return match(true) {
            str_contains($className, 'Mission') => 'Mission',
            str_contains($className, 'Shift') => 'Shift',
            str_contains($className, 'Participation') => 'ParticipationRequest',
            default => 'Unknown',
        };
    }

    /**
     * Εξαγωγή ID οντότητας από event.
     */
    protected function getEntityId(object $event): ?int
    {
        if (isset($event->mission)) {
            return $event->mission->id;
        }
        if (isset($event->shift)) {
            return $event->shift->id;
        }
        if (isset($event->participation)) {
            return $event->participation->id;
        }
        
        return null;
    }

    /**
     * Εξαγωγή ενέργειας από event.
     */
    protected function getAction(object $event): string
    {
        $className = class_basename($event);
        
        return match($className) {
            'MissionPublished' => 'ΔΗΜΟΣΙΕΥΣΗ_ΑΠΟΣΤΟΛΗΣ',
            'ShiftFull' => 'ΠΛΗΡΗΣ_ΒΑΡΔΙΑ',
            'ParticipationRequested' => 'ΑΙΤΗΣΗ_ΣΥΜΜΕΤΟΧΗΣ',
            'ParticipationApproved' => 'ΕΓΚΡΙΣΗ_ΣΥΜΜΕΤΟΧΗΣ',
            'ParticipationRejected' => 'ΑΠΟΡΡΙΨΗ_ΣΥΜΜΕΤΟΧΗΣ',
            default => 'ΑΓΝΩΣΤΗ_ΕΝΕΡΓΕΙΑ',
        };
    }

    /**
     * Εξαγωγή δεδομένων event.
     */
    protected function getEventData(object $event): ?array
    {
        if (isset($event->mission)) {
            return $event->mission->toArray();
        }
        if (isset($event->shift)) {
            return $event->shift->toArray();
        }
        if (isset($event->participation)) {
            return $event->participation->toArray();
        }
        
        return null;
    }
}
