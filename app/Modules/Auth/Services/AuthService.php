<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Εγγραφή νέου χρήστη.
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'role' => User::ROLE_VOLUNTEER,
            'is_active' => true,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->auditService->log(
            actor: $user,
            action: 'ΕΓΓΡΑΦΗ_ΧΡΗΣΤΗ',
            entityType: 'User',
            entityId: $user->id,
            after: $user->toArray()
        );

        return [
            'user' => $this->formatUser($user),
            'token' => $token,
        ];
    }

    /**
     * Σύνδεση χρήστη.
     */
    public function login(array $credentials): ?array
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if (!$user->is_active) {
            return null;
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->auditService->log(
            actor: $user,
            action: 'ΣΥΝΔΕΣΗ_ΧΡΗΣΤΗ',
            entityType: 'User',
            entityId: $user->id
        );

        return [
            'user' => $this->formatUser($user),
            'token' => $token,
        ];
    }

    /**
     * Αποσύνδεση χρήστη.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();

        $this->auditService->log(
            actor: $user,
            action: 'ΑΠΟΣΥΝΔΕΣΗ_ΧΡΗΣΤΗ',
            entityType: 'User',
            entityId: $user->id
        );
    }

    /**
     * Λήψη τρέχοντος χρήστη με σχέσεις.
     */
    public function getCurrentUser(User $user): array
    {
        $user->load(['department', 'volunteerProfile']);
        
        return $this->formatUser($user);
    }

    /**
     * Ενημέρωση προφίλ χρήστη.
     */
    public function updateProfile(User $user, array $data): array
    {
        $before = $user->toArray();

        $user->update([
            'name' => $data['name'] ?? $user->name,
            'phone' => $data['phone'] ?? $user->phone,
        ]);

        $this->auditService->log(
            actor: $user,
            action: 'ΕΝΗΜΕΡΩΣΗ_ΠΡΟΦΙΛ',
            entityType: 'User',
            entityId: $user->id,
            before: $before,
            after: $user->toArray()
        );

        return $this->formatUser($user);
    }

    /**
     * Αλλαγή κωδικού πρόσβασης.
     */
    public function changePassword(User $user, array $data): bool
    {
        if (!Hash::check($data['current_password'], $user->password)) {
            return false;
        }

        $user->update([
            'password' => Hash::make($data['new_password']),
        ]);

        $this->auditService->log(
            actor: $user,
            action: 'ΑΛΛΑΓΗ_ΚΩΔΙΚΟΥ',
            entityType: 'User',
            entityId: $user->id
        );

        return true;
    }

    /**
     * Ανανέωση token.
     */
    public function refreshToken(User $user): string
    {
        $user->currentAccessToken()->delete();
        
        return $user->createToken('auth_token')->plainTextToken;
    }

    /**
     * Μορφοποίηση δεδομένων χρήστη για API.
     */
    protected function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'όνομα' => $user->name,
            'email' => $user->email,
            'τηλέφωνο' => $user->phone,
            'ρόλος' => $user->role,
            'ρόλος_ετικέτα' => $user->role_label,
            'τμήμα' => $user->department ? [
                'id' => $user->department->id,
                'όνομα' => $user->department->name,
            ] : null,
            'προφίλ_εθελοντή' => $user->volunteerProfile ? [
                'αριθμός_μητρώου' => $user->volunteerProfile->registry_no,
                'βαθμός' => $user->volunteerProfile->rank,
                'ημερομηνία_ένταξης' => $user->volunteerProfile->joined_at,
            ] : null,
            'ενεργός' => $user->is_active,
            'δημιουργήθηκε' => $user->created_at,
        ];
    }
}
