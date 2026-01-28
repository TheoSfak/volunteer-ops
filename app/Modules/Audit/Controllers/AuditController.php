<?php

namespace App\Modules\Audit\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Λίστα audit logs.
     */
    public function index(Request $request): JsonResponse
    {
        // Μόνο διαχειριστές
        if (!$request->user()->hasAnyRole([User::ROLE_SYSTEM_ADMIN, User::ROLE_DEPARTMENT_ADMIN])) {
            return response()->json([
                'μήνυμα' => 'Δεν έχετε δικαίωμα πρόσβασης.',
            ], 403);
        }

        $logs = $this->auditService->getHistory($request->all());

        return response()->json([
            'ιστορικό' => $logs['data'],
            'σύνολο' => $logs['total'],
            'σελίδα' => $logs['current_page'],
            'ανά_σελίδα' => $logs['per_page'],
        ]);
    }

    /**
     * Ιστορικό συγκεκριμένης οντότητας.
     */
    public function entityHistory(Request $request, string $type, int $id): JsonResponse
    {
        // Μόνο διαχειριστές
        if (!$request->user()->hasAnyRole([User::ROLE_SYSTEM_ADMIN, User::ROLE_DEPARTMENT_ADMIN])) {
            return response()->json([
                'μήνυμα' => 'Δεν έχετε δικαίωμα πρόσβασης.',
            ], 403);
        }

        $history = $this->auditService->getEntityHistory($type, $id);

        return response()->json([
            'ιστορικό' => $history,
        ]);
    }
}
