<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\RegisterRequest;
use App\Modules\Auth\Requests\UpdateProfileRequest;
use App\Modules\Auth\Requests\ChangePasswordRequest;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * Εγγραφή νέου χρήστη.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return response()->json([
            'μήνυμα' => 'Η εγγραφή ολοκληρώθηκε επιτυχώς.',
            'χρήστης' => $result['user'],
            'token' => $result['token'],
        ], 201);
    }

    /**
     * Σύνδεση χρήστη.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        if (!$result) {
            return response()->json([
                'μήνυμα' => 'Λανθασμένα στοιχεία σύνδεσης.',
            ], 401);
        }

        return response()->json([
            'μήνυμα' => 'Η σύνδεση ολοκληρώθηκε επιτυχώς.',
            'χρήστης' => $result['user'],
            'token' => $result['token'],
        ]);
    }

    /**
     * Αποσύνδεση χρήστη.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json([
            'μήνυμα' => 'Η αποσύνδεση ολοκληρώθηκε επιτυχώς.',
        ]);
    }

    /**
     * Λήψη στοιχείων τρέχοντος χρήστη.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->authService->getCurrentUser($request->user());

        return response()->json([
            'χρήστης' => $user,
        ]);
    }

    /**
     * Ενημέρωση προφίλ χρήστη.
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->authService->updateProfile(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'μήνυμα' => 'Το προφίλ ενημερώθηκε επιτυχώς.',
            'χρήστης' => $user,
        ]);
    }

    /**
     * Αλλαγή κωδικού πρόσβασης.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $result = $this->authService->changePassword(
            $request->user(),
            $request->validated()
        );

        if (!$result) {
            return response()->json([
                'μήνυμα' => 'Ο τρέχων κωδικός είναι λανθασμένος.',
            ], 400);
        }

        return response()->json([
            'μήνυμα' => 'Ο κωδικός πρόσβασης άλλαξε επιτυχώς.',
        ]);
    }

    /**
     * Ανανέωση token.
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $token = $this->authService->refreshToken($request->user());

        return response()->json([
            'μήνυμα' => 'Το token ανανεώθηκε επιτυχώς.',
            'token' => $token,
        ]);
    }
}
