<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Επιστροφή διαδρομής σε περίπτωση μη αυθεντικοποιημένου χρήστη.
     */
    protected function redirectTo(Request $request): ?string
    {
        // Για API, επιστρέφουμε null (401 response)
        return null;
    }

    /**
     * Προσαρμοσμένο μήνυμα σφάλματος.
     */
    protected function unauthenticated($request, array $guards)
    {
        abort(response()->json([
            'μήνυμα' => 'Απαιτείται σύνδεση.',
            'σφάλμα' => 'unauthenticated',
        ], 401));
    }
}
