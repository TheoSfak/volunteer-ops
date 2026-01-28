<?php

namespace App\Modules\Directory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Requests\StoreDepartmentRequest;
use App\Modules\Directory\Requests\UpdateDepartmentRequest;
use App\Modules\Directory\Services\DepartmentService;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function __construct(
        protected DepartmentService $departmentService
    ) {}

    /**
     * Λίστα όλων των τμημάτων.
     */
    public function index(): JsonResponse
    {
        $departments = $this->departmentService->getAll();

        return response()->json([
            'τμήματα' => $departments,
        ]);
    }

    /**
     * Δημιουργία νέου τμήματος.
     */
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        $department = $this->departmentService->create($request->validated());

        return response()->json([
            'μήνυμα' => 'Το τμήμα δημιουργήθηκε επιτυχώς.',
            'τμήμα' => $department,
        ], 201);
    }

    /**
     * Προβολή συγκεκριμένου τμήματος.
     */
    public function show(int $id): JsonResponse
    {
        $department = $this->departmentService->getById($id);

        if (!$department) {
            return response()->json([
                'μήνυμα' => 'Το τμήμα δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'τμήμα' => $department,
        ]);
    }

    /**
     * Ενημέρωση τμήματος.
     */
    public function update(UpdateDepartmentRequest $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);
        $this->authorize('update', $department);

        $department = $this->departmentService->update($id, $request->validated());

        return response()->json([
            'μήνυμα' => 'Το τμήμα ενημερώθηκε επιτυχώς.',
            'τμήμα' => $department,
        ]);
    }

    /**
     * Διαγραφή τμήματος.
     */
    public function destroy(int $id): JsonResponse
    {
        $department = Department::findOrFail($id);
        $this->authorize('delete', $department);

        $this->departmentService->delete($id);

        return response()->json([
            'μήνυμα' => 'Το τμήμα διαγράφηκε επιτυχώς.',
        ]);
    }

    /**
     * Λίστα χρηστών τμήματος.
     */
    public function users(int $id): JsonResponse
    {
        $users = $this->departmentService->getUsers($id);

        return response()->json([
            'χρήστες' => $users,
        ]);
    }
}
