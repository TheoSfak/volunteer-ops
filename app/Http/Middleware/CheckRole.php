<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Έλεγχος ρόλου χρήστη.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'μήνυμα' => 'Απαιτείται σύνδεση.',
            ], 401);
        }

        if (!in_array($user->role, $roles)) {
            return response()->json([
                'μήνυμα' => 'Δεν έχετε δικαίωμα πρόσβασης σε αυτή την ενέργεια.',
                'απαιτούμενοι_ρόλοι' => array_map(
                    fn($role) => \App\Models\User::ROLE_LABELS[$role] ?? $role,
                    $roles
                ),
            ], 403);
        }

        return $next($request);
    }
}
