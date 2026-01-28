<?php

namespace App\Modules\Documents\Policies;

use App\Models\User;
use App\Modules\Documents\Models\Document;
use Illuminate\Auth\Access\HandlesAuthorization;

class DocumentPolicy
{
    use HandlesAuthorization;

    /**
     * Προβολή εγγράφων.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Προβολή συγκεκριμένου εγγράφου.
     */
    public function view(User $user, Document $document): bool
    {
        // Δημόσια έγγραφα
        if ($document->visibility === Document::VISIBILITY_PUBLIC) {
            return true;
        }

        // Έγγραφα διαχειριστών
        if ($document->visibility === Document::VISIBILITY_ADMINS && $user->isAdmin()) {
            return true;
        }

        // Διαχειριστής συστήματος
        if ($user->hasRole(User::ROLE_SYSTEM_ADMIN)) {
            return true;
        }

        // Έγγραφα τμήματος
        if ($document->department_id && $user->department_id === $document->department_id) {
            return true;
        }

        return false;
    }

    /**
     * Δημιουργία εγγράφου.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            User::ROLE_SYSTEM_ADMIN,
            User::ROLE_DEPARTMENT_ADMIN,
        ]);
    }

    /**
     * Διαγραφή εγγράφου.
     */
    public function delete(User $user, Document $document): bool
    {
        // Διαχειριστής συστήματος
        if ($user->hasRole(User::ROLE_SYSTEM_ADMIN)) {
            return true;
        }

        // Διαχειριστής τμήματος για έγγραφα του τμήματός του
        if ($user->hasRole(User::ROLE_DEPARTMENT_ADMIN) && 
            $document->department_id === $user->department_id) {
            return true;
        }

        return false;
    }
}
