<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Έλεγχος ότι ο χρήστης είναι ενεργός.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && !$request->user()->is_active) {
            return response()->json([
                'μήνυμα' => 'Ο λογαριασμός σας έχει απενεργοποιηθεί.',
                'σφάλμα' => 'account_disabled',
            ], 403);
        }

        return $next($request);
    }
}
