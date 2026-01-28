<?php

namespace App\Modules\Directory\Services;

use App\Modules\Directory\Models\Department;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Support\Facades\Auth;

class DepartmentService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Λήψη όλων των τμημάτων.
     */
    public function getAll(): array
    {
        $departments = Department::with(['parent', 'children'])
            ->active()
            ->get();

        return $departments->map(fn($dept) => $this->formatDepartment($dept))->toArray();
    }

    /**
     * Λήψη τμήματος με ID.
     */
    public function getById(int $id): ?array
    {
        $department = Department::with(['parent', 'children', 'users'])
            ->find($id);

        if (!$department) {
            return null;
        }

        return $this->formatDepartment($department);
    }

    /**
     * Δημιουργία νέου τμήματος.
     */
    public function create(array $data): array
    {
        $department = Department::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΔΗΜΙΟΥΡΓΙΑ_ΤΜΗΜΑΤΟΣ',
            entityType: 'Department',
            entityId: $department->id,
            after: $department->toArray()
        );

        return $this->formatDepartment($department);
    }

    /**
     * Ενημέρωση τμήματος.
     */
    public function update(int $id, array $data): array
    {
        $department = Department::findOrFail($id);
        $before = $department->toArray();

        $department->update($data);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΕΝΗΜΕΡΩΣΗ_ΤΜΗΜΑΤΟΣ',
            entityType: 'Department',
            entityId: $department->id,
            before: $before,
            after: $department->toArray()
        );

        return $this->formatDepartment($department->fresh());
    }

    /**
     * Διαγραφή τμήματος (soft delete).
     */
    public function delete(int $id): void
    {
        $department = Department::findOrFail($id);
        $before = $department->toArray();

        $department->delete();

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΔΙΑΓΡΑΦΗ_ΤΜΗΜΑΤΟΣ',
            entityType: 'Department',
            entityId: $id,
            before: $before
        );
    }

    /**
     * Λήψη χρηστών τμήματος.
     */
    public function getUsers(int $id): array
    {
        $department = Department::with('users')->findOrFail($id);

        return $department->users->map(fn($user) => [
            'id' => $user->id,
            'όνομα' => $user->name,
            'email' => $user->email,
            'ρόλος' => $user->role,
            'ρόλος_ετικέτα' => $user->role_label,
        ])->toArray();
    }

    /**
     * Μορφοποίηση τμήματος για API.
     */
    protected function formatDepartment(Department $department): array
    {
        return [
            'id' => $department->id,
            'όνομα' => $department->name,
            'κωδικός' => $department->code,
            'περιγραφή' => $department->description,
            'γονικό_τμήμα' => $department->parent ? [
                'id' => $department->parent->id,
                'όνομα' => $department->parent->name,
            ] : null,
            'θυγατρικά_τμήματα' => $department->children ? $department->children->map(fn($child) => [
                'id' => $child->id,
                'όνομα' => $child->name,
            ])->toArray() : [],
            'ενεργό' => $department->is_active,
            'δημιουργήθηκε' => $department->created_at,
            'ενημερώθηκε' => $department->updated_at,
        ];
    }
}
