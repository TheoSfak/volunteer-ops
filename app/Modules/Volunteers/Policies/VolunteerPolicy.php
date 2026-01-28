<?php

namespace App\Modules\Volunteers\Policies;

use App\Models\User;
use App\Modules\Volunteers\Models\VolunteerProfile;
use Illuminate\Auth\Access\HandlesAuthorization;

class VolunteerPolicy
{
    use HandlesAuthorization;

    /**
     * Προβολή λίστας εθελοντών.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->hasRole(User::ROLE_SHIFT_LEADER);
    }

    /**
     * Προβολή συγκεκριμένου εθελοντή.
     */
    public function view(User $user, VolunteerProfile $profile): bool
    {
        // Ο εθελοντής μπορεί να δει τα δικά του στοιχεία
        if ($user->id === $profile->user_id) {
            return true;
        }

        // Οι διαχειριστές μπορούν να δουν όλους τους εθελοντές
        if ($user->isAdmin()) {
            return true;
        }

        // Οι αρχηγοί βάρδιας μπορούν να δουν εθελοντές του τμήματός τους
        if ($user->hasRole(User::ROLE_SHIFT_LEADER)) {
            return $user->department_id === $profile->user->department_id;
        }

        return false;
    }

    /**
     * Ενημέρωση εθελοντή.
     */
    public function update(User $user, VolunteerProfile $profile): bool
    {
        // Ο εθελοντής μπορεί να επεξεργαστεί μόνο βασικά στοιχεία του
        if ($user->id === $profile->user_id) {
            return true;
        }

        // Διαχειριστής συστήματος μπορεί να επεξεργαστεί όλους
        if ($user->hasRole(User::ROLE_SYSTEM_ADMIN)) {
            return true;
        }

        // Διαχειριστής τμήματος μπορεί να επεξεργαστεί εθελοντές του τμήματός του
        if ($user->hasRole(User::ROLE_DEPARTMENT_ADMIN)) {
            return $user->department_id === $profile->user->department_id;
        }

        return false;
    }
}
