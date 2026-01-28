<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Directory\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /**
     * Λίστα τμημάτων.
     */
    public function index()
    {
        $departments = Department::withCount('users')->orderBy('name')->paginate(20);
        return view('departments.index', compact('departments'));
    }

    /**
     * Φόρμα δημιουργίας τμήματος.
     */
    public function create()
    {
        return view('departments.create');
    }

    /**
     * Αποθήκευση νέου τμήματος.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:departments,name',
            'description' => 'nullable|string|max:500',
        ]);

        Department::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->route('departments.index')
            ->with('success', 'Το τμήμα δημιουργήθηκε επιτυχώς.');
    }

    /**
     * Φόρμα επεξεργασίας τμήματος.
     */
    public function edit(Department $department)
    {
        return view('departments.edit', compact('department'));
    }

    /**
     * Ενημέρωση τμήματος.
     */
    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:departments,name,' . $department->id,
            'description' => 'nullable|string|max:500',
        ]);

        $department->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('departments.index')
            ->with('success', 'Το τμήμα ενημερώθηκε επιτυχώς.');
    }

    /**
     * Διαγραφή τμήματος.
     */
    public function destroy(Department $department)
    {
        if ($department->users()->count() > 0) {
            return back()->with('error', 'Δεν μπορεί να διαγραφεί τμήμα με εθελοντές.');
        }

        $department->delete();

        return redirect()->route('departments.index')
            ->with('success', 'Το τμήμα διαγράφηκε επιτυχώς.');
    }
}
