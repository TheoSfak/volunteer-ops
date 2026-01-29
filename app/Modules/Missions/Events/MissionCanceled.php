<?php

namespace App\Modules\Missions\Events;

use App\Modules\Missions\Models\Mission;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MissionCanceled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Η αποστολή που ακυρώθηκε.
     */
    public Mission $mission;

    /**
     * Ο λόγος ακύρωσης.
     */
    public ?string $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(Mission $mission, ?string $reason = null)
    {
        $this->mission = $mission;
        $this->reason = $reason;
    }
}
