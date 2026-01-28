<?php

namespace App\Modules\Shifts\Events;

use App\Modules\Shifts\Models\Shift;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShiftFull
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Δημιουργία νέου event.
     */
    public function __construct(
        public Shift $shift
    ) {}
}
