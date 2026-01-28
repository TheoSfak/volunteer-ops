<?php

namespace App\Modules\Missions\Policies;

use App\Models\User;
use App\Modules\Missions\Models\Mission;
use Illuminate\Auth\Access\HandlesAuthorization;

class MissionPolicy
{
    use HandlesAuthorization;

    /**
     * Προβολή λίστας αποστολών.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Προβολή συγκεκριμένης αποστολής.
     */
    public function view(User $user, Mission $mission): bool
    {
        return true;
    }

    /**
     * Δημιουργία νέας αποστολής.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            User::ROLE_SYSTEM_ADMIN,
            User::ROLE_DEPARTMENT_ADMIN,
        ]);
    }

    /**
     * Ενημέρωση αποστολής.
     */
    public function update(User $user, Mission $mission): bool
    {
        // Διαχειριστής συστήματος μπορεί να ενημερώσει οτιδήποτε
        if ($user->hasRole(User::ROLE_SYSTEM_ADMIN)) {
            return true;
        }

        // Διαχειριστής τμήματος μπορεί να ενημερώσει αποστολές του τμήματός του
        if ($user->hasRole(User::ROLE_DEPARTMENT_ADMIN)) {
            return $user->department_id === $mission->department_id;
        }

        return false;
    }

    /**
     * Δημοσίευση αποστολής.
     */
    public function publish(User $user, Mission $mission): bool
    {
        return $this->update($user, $mission);
    }

    /**
     * Κλείσιμο αποστολής.
     */
    public function close(User $user, Mission $mission): bool
    {
        return $this->update($user, $mission);
    }

    /**
     * Ακύρωση αποστολής.
     */
    public function cancel(User $user, Mission $mission): bool
    {
        return $this->update($user, $mission);
    }

    /**
     * Διαγραφή αποστολής.
     */
    public function delete(User $user, Mission $mission): bool
    {
        return $user->hasRole(User::ROLE_SYSTEM_ADMIN);
    }
}
