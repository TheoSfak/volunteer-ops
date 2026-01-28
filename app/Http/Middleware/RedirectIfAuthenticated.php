<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Redirect αν ο χρήστης είναι ήδη συνδεδεμένος.
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                // Για API, επιστρέφουμε JSON response
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'μήνυμα' => 'Είστε ήδη συνδεδεμένος.',
                    ], 400);
                }
                
                return redirect(RouteServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}
