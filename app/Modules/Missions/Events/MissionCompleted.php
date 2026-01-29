<?php

namespace App\Modules\Missions\Events;

use App\Modules\Missions\Models\Mission;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MissionCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Η αποστολή που ολοκληρώθηκε.
     */
    public Mission $mission;

    /**
     * Create a new event instance.
     */
    public function __construct(Mission $mission)
    {
        $this->mission = $mission;
    }
}
