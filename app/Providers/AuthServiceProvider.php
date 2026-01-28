<?php

namespace App\Providers;

use App\Modules\Missions\Models\Mission;
use App\Modules\Missions\Policies\MissionPolicy;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Shifts\Policies\ShiftPolicy;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Participation\Policies\ParticipationPolicy;
use App\Modules\Volunteers\Models\VolunteerProfile;
use App\Modules\Volunteers\Policies\VolunteerPolicy;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Policies\DocumentPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Αντιστοιχίσεις πολιτικών μοντέλων.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Mission::class => MissionPolicy::class,
        Shift::class => ShiftPolicy::class,
        ParticipationRequest::class => ParticipationPolicy::class,
        VolunteerProfile::class => VolunteerPolicy::class,
        Document::class => DocumentPolicy::class,
    ];

    /**
     * Καταχώρηση υπηρεσιών αυθεντικοποίησης/εξουσιοδότησης.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Ρόλος Διαχειριστή Συστήματος - πλήρης πρόσβαση
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('SYSTEM_ADMIN')) {
                return true;
            }
        });

        // Πύλη για Διαχειριστή Τμήματος
        Gate::define('manage-department', function ($user, $departmentId) {
            return $user->hasRole('DEPARTMENT_ADMIN') && 
                   $user->department_id === $departmentId;
        });

        // Πύλη για Αρχηγό Βάρδιας
        Gate::define('manage-shift', function ($user, $shift) {
            return $user->hasRole('SHIFT_LEADER') && 
                   $shift->leader_user_id === $user->id;
        });
    }
}
