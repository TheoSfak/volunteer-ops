<?php

namespace App\Modules\Notifications\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * GET /notifications
     * Λίστα ειδοποιήσεων τρέχοντα χρήστη.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 20);
        $notifications = $this->notificationService->getUserNotifications(
            $request->user(),
            $limit
        );

        return response()->json([
            'μήνυμα' => 'Οι ειδοποιήσεις σας.',
            'ειδοποιήσεις' => $notifications,
        ]);
    }

    /**
     * GET /notifications/unread-count
     * Αριθμός μη αναγνωσμένων.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->getUnreadCount($request->user());

        return response()->json([
            'μη_αναγνωσμένες' => $count,
        ]);
    }

    /**
     * PATCH /notifications/{id}/read
     * Σήμανση ως αναγνωσμένη.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $success = $this->notificationService->markAsRead($id, $request->user());

        if (!$success) {
            return response()->json([
                'μήνυμα' => 'Η ειδοποίηση δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'μήνυμα' => 'Η ειδοποίηση σημειώθηκε ως αναγνωσμένη.',
        ]);
    }

    /**
     * PATCH /notifications/read-all
     * Σήμανση όλων ως αναγνωσμένες.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->notificationService->markAllAsRead($request->user());

        return response()->json([
            'μήνυμα' => 'Όλες οι ειδοποιήσεις σημειώθηκαν ως αναγνωσμένες.',
        ]);
    }
}
