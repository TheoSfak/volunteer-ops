<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    /**
     * Εμφάνιση σελίδας προφίλ.
     */
    public function index()
    {
        $user = Auth::user()->load(['department', 'skills', 'volunteerProfile']);
        
        return view('profile.index', compact('user'));
    }

    /**
     * Ενημέρωση στοιχείων προφίλ.
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
        ]);

        $user->update($validated);

        return back()->with('success', 'Τα στοιχεία σας ενημερώθηκαν επιτυχώς.');
    }

    /**
     * Αλλαγή κωδικού πρόσβασης.
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'current_password.current_password' => 'Ο τρέχων κωδικός δεν είναι σωστός.',
            'password.min' => 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.',
            'password.confirmed' => 'Οι κωδικοί δεν ταιριάζουν.',
        ]);

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('success', 'Ο κωδικός σας άλλαξε επιτυχώς.');
    }

    /**
     * Ενημέρωση δεξιοτήτων/διπλωμάτων.
     */
    public function updateSkills(Request $request)
    {
        $validated = $request->validate([
            'skills' => 'nullable|array',
            'skills.*' => 'exists:skills,id',
        ]);

        $user = Auth::user();
        
        // Sync skills - αφαιρεί τις παλιές και προσθέτει τις νέες
        $skillIds = $validated['skills'] ?? [];
        $user->skills()->sync($skillIds);

        return back()->with('success', 'Οι δεξιότητες ενημερώθηκαν επιτυχώς.');
    }
}
