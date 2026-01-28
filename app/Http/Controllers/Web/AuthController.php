<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Εμφάνιση φόρμας σύνδεσης.
     */
    public function showLogin()
    {
        return view('auth.login');
    }

    /**
     * Σύνδεση χρήστη.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ], [
            'email.required' => 'Το email είναι υποχρεωτικό.',
            'email.email' => 'Μη έγκυρο email.',
            'password.required' => 'Ο κωδικός είναι υποχρεωτικός.',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors([
            'email' => 'Τα στοιχεία σύνδεσης δεν είναι σωστά.',
        ])->onlyInput('email');
    }

    /**
     * Εμφάνιση φόρμας εγγραφής.
     */
    public function showRegister()
    {
        return view('auth.register');
    }

    /**
     * Εγγραφή νέου χρήστη.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'name.required' => 'Το ονοματεπώνυμο είναι υποχρεωτικό.',
            'email.required' => 'Το email είναι υποχρεωτικό.',
            'email.unique' => 'Αυτό το email χρησιμοποιείται ήδη.',
            'password.required' => 'Ο κωδικός είναι υποχρεωτικός.',
            'password.confirmed' => 'Οι κωδικοί δεν ταιριάζουν.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_VOLUNTEER,
            'is_active' => true,
        ]);

        Auth::login($user);

        return redirect()->route('dashboard')->with('success', 'Καλώς ήρθατε!');
    }

    /**
     * Αποσύνδεση χρήστη.
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect()->route('login');
    }

    /**
     * Εμφάνιση φόρμας ξεχασμένου κωδικού.
     */
    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Αποστολή email επαναφοράς κωδικού.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ], [
            'email.exists' => 'Δεν βρέθηκε λογαριασμός με αυτό το email.',
        ]);

        // TODO: Implement password reset
        return back()->with('success', 'Αν το email υπάρχει, θα λάβετε οδηγίες επαναφοράς.');
    }
}
