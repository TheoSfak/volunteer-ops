<?php

namespace App\Modules\Missions\Events;

use App\Modules\Missions\Models\Mission;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MissionPublished
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Δημιουργία νέου event.
     */
    public function __construct(
        public Mission $mission
    ) {}
}
