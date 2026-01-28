<?php

namespace App\Modules\Shifts\Policies;

use App\Models\User;
use App\Modules\Shifts\Models\Shift;
use Illuminate\Auth\Access\HandlesAuthorization;

class ShiftPolicy
{
    use HandlesAuthorization;

    /**
     * Προβολή βαρδιών.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Προβολή συγκεκριμένης βάρδιας.
     */
    public function view(User $user, Shift $shift): bool
    {
        return true;
    }

    /**
     * Δημιουργία νέας βάρδιας.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            User::ROLE_SYSTEM_ADMIN,
            User::ROLE_DEPARTMENT_ADMIN,
        ]);
    }

    /**
     * Ενημέρωση βάρδιας.
     */
    public function update(User $user, Shift $shift): bool
    {
        // Διαχειριστής συστήματος μπορεί να ενημερώσει οτιδήποτε
        if ($user->hasRole(User::ROLE_SYSTEM_ADMIN)) {
            return true;
        }

        // Διαχειριστής τμήματος μπορεί να ενημερώσει βάρδιες του τμήματός του
        if ($user->hasRole(User::ROLE_DEPARTMENT_ADMIN)) {
            return $user->department_id === $shift->mission->department_id;
        }

        // Αρχηγός βάρδιας μπορεί να ενημερώσει μόνο τη δική του βάρδια
        if ($user->hasRole(User::ROLE_SHIFT_LEADER)) {
            return $shift->leader_user_id === $user->id;
        }

        return false;
    }

    /**
     * Κλείδωμα βάρδιας.
     */
    public function lock(User $user, Shift $shift): bool
    {
        return $this->update($user, $shift);
    }

    /**
     * Διαγραφή βάρδιας.
     */
    public function delete(User $user, Shift $shift): bool
    {
        return $user->hasAnyRole([
            User::ROLE_SYSTEM_ADMIN,
            User::ROLE_DEPARTMENT_ADMIN,
        ]) && ($user->hasRole(User::ROLE_SYSTEM_ADMIN) || 
               $user->department_id === $shift->mission->department_id);
    }
}
