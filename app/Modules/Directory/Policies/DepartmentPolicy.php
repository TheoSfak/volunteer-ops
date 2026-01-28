<?php

namespace App\Modules\Directory\Policies;

use App\Models\User;
use App\Modules\Directory\Models\Department;
use Illuminate\Auth\Access\HandlesAuthorization;

class DepartmentPolicy
{
    use HandlesAuthorization;

    /**
     * Προβολή λίστας τμημάτων.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Προβολή συγκεκριμένου τμήματος.
     */
    public function view(User $user, Department $department): bool
    {
        return true;
    }

    /**
     * Δημιουργία νέου τμήματος.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_SYSTEM_ADMIN);
    }

    /**
     * Ενημέρωση τμήματος.
     */
    public function update(User $user, Department $department): bool
    {
        // Διαχειριστής συστήματος μπορεί να ενημερώσει οποιοδήποτε τμήμα
        if ($user->hasRole(User::ROLE_SYSTEM_ADMIN)) {
            return true;
        }

        // Διαχειριστής τμήματος μπορεί να ενημερώσει μόνο το δικό του τμήμα
        if ($user->hasRole(User::ROLE_DEPARTMENT_ADMIN)) {
            return $user->department_id === $department->id;
        }

        return false;
    }

    /**
     * Διαγραφή τμήματος.
     */
    public function delete(User $user, Department $department): bool
    {
        return $user->hasRole(User::ROLE_SYSTEM_ADMIN);
    }
}
