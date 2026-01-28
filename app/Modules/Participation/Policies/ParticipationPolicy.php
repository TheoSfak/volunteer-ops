<?php

namespace App\Modules\Participation\Policies;

use App\Models\User;
use App\Modules\Participation\Models\ParticipationRequest;
use Illuminate\Auth\Access\HandlesAuthorization;

class ParticipationPolicy
{
    use HandlesAuthorization;

    /**
     * Προβολή αίτησης.
     */
    public function view(User $user, ParticipationRequest $participation): bool
    {
        // Ο ίδιος ο χρήστης
        if ($user->id === $participation->user_id) {
            return true;
        }

        // Διαχειριστές
        if ($user->hasRole(User::ROLE_SYSTEM_ADMIN)) {
            return true;
        }

        // Διαχειριστής τμήματος
        if ($user->hasRole(User::ROLE_DEPARTMENT_ADMIN)) {
            return $user->department_id === $participation->shift->mission->department_id;
        }

        // Αρχηγός βάρδιας
        if ($user->hasRole(User::ROLE_SHIFT_LEADER)) {
            return $participation->shift->leader_user_id === $user->id;
        }

        return false;
    }

    /**
     * Έγκριση αίτησης.
     */
    public function approve(User $user, ParticipationRequest $participation): bool
    {
        return $this->canDecide($user, $participation);
    }

    /**
     * Απόρριψη αίτησης.
     */
    public function reject(User $user, ParticipationRequest $participation): bool
    {
        return $this->canDecide($user, $participation);
    }

    /**
     * Ακύρωση αίτησης.
     */
    public function cancel(User $user, ParticipationRequest $participation): bool
    {
        // Ο ίδιος ο χρήστης μπορεί να ακυρώσει τη δική του αίτηση
        if ($user->id === $participation->user_id) {
            return true;
        }

        // Διαχειριστές μπορούν να ακυρώσουν
        return $this->canDecide($user, $participation);
    }

    /**
     * Έλεγχος αν ο χρήστης μπορεί να λάβει απόφαση.
     */
    protected function canDecide(User $user, ParticipationRequest $participation): bool
    {
        // Διαχειριστής συστήματος
        if ($user->hasRole(User::ROLE_SYSTEM_ADMIN)) {
            return true;
        }

        // Διαχειριστής τμήματος για αποστολές του τμήματός του
        if ($user->hasRole(User::ROLE_DEPARTMENT_ADMIN)) {
            return $user->department_id === $participation->shift->mission->department_id;
        }

        // Αρχηγός βάρδιας για τη δική του βάρδια
        if ($user->hasRole(User::ROLE_SHIFT_LEADER)) {
            return $participation->shift->leader_user_id === $user->id;
        }

        return false;
    }
}
