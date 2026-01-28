<?php

namespace App\Providers;

use App\Modules\Missions\Events\MissionPublished;
use App\Modules\Shifts\Events\ShiftFull;
use App\Modules\Participation\Events\ParticipationRequested;
use App\Modules\Participation\Events\ParticipationApproved;
use App\Modules\Participation\Events\ParticipationRejected;
use App\Modules\Notifications\Listeners\SendNotification;
use App\Modules\Audit\Listeners\WriteAuditLog;
use App\Modules\Reports\Listeners\UpdateCoverageStats;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Αντιστοιχίσεις event listeners.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // Γεγονότα Αποστολών
        MissionPublished::class => [
            SendNotification::class,
            WriteAuditLog::class,
        ],

        // Γεγονότα Βαρδιών
        ShiftFull::class => [
            SendNotification::class,
            WriteAuditLog::class,
            UpdateCoverageStats::class,
        ],

        // Γεγονότα Συμμετοχών
        ParticipationRequested::class => [
            SendNotification::class,
            WriteAuditLog::class,
        ],

        ParticipationApproved::class => [
            SendNotification::class,
            WriteAuditLog::class,
            UpdateCoverageStats::class,
        ],

        ParticipationRejected::class => [
            SendNotification::class,
            WriteAuditLog::class,
        ],
    ];

    /**
     * Καταχώρηση γεγονότων εφαρμογής.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Καθορισμός αν τα γεγονότα θα ανακαλύπτονται αυτόματα.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
