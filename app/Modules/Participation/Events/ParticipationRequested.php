<?php

namespace App\Modules\Participation\Events;

use App\Modules\Participation\Models\ParticipationRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipationRequested
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Δημιουργία νέου event.
     */
    public function __construct(
        public ParticipationRequest $participation
    ) {}
}
